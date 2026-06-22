<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Ban;
use App\Models\BlocklistEntry;
use App\Models\RegistrationCheck;
use Illuminate\Support\Str;

/**
 * Layer 1 of the anti-spam subsystem (ADR-0007 §2.2): the registration screener.
 *
 * Runs the local + crowdsourced controls and returns a TRI-STATE decision (allow / flag / block), mirroring
 * the ACL. The guiding rule is FLAG-DON'T-BLOCK on uncertainty — a high-confidence signal blocks, a
 * borderline one flags (→ the new-user moderation queue), and an absent external service degrades to local
 * signals rather than erroring. Every attempt is recorded to `registration_checks` (PII under a retention
 * purge, security §2.6).
 */
final class RegistrationGuard
{
    public function __construct(private readonly StopForumSpamClient $sfs) {}

    /**
     * @param  array{email?:string, username?:string, ip?:string}  $context
     */
    public function screen(array $context): ScreeningResult
    {
        $email = strtolower(trim((string) ($context['email'] ?? '')));
        $username = (string) ($context['username'] ?? '');
        $ip = (string) ($context['ip'] ?? '');

        $reg = (array) config('novfora.antispam.registration', []);
        $scores = [];
        $reasons = [];
        $degraded = false;
        $decision = ScreeningResult::ALLOW;

        $rank = [ScreeningResult::ALLOW => 0, ScreeningResult::FLAG => 1, ScreeningResult::BLOCK => 2];
        $escalate = function (string $to) use (&$decision, $rank) {
            if ($rank[$to] > $rank[$decision]) {
                $decision = $to;
            }
        };

        // 1. An existing IP/email ban is an absolute block.
        if ($this->banned($ip, $email)) {
            $escalate(ScreeningResult::BLOCK);
            $reasons[] = 'banned';
            $scores['ban'] = true;
        }

        // 2. Disposable / throwaway email domain → block (strong, local signal; configurable).
        if (($reg['disposable_email']['enabled'] ?? true) && $this->disposable($email)) {
            $escalate(ScreeningResult::BLOCK);
            $reasons[] = 'disposable_email';
            $scores['disposable'] = true;
        }

        // 2b. A cron-warmed StopForumSpam toxic email domain (phase-1.5 F-C) → flag, never hard-block: a
        //     domain-level signal is suggestive, not certain, so it honours flag-don't-block.
        if ($this->toxicDomain($email)) {
            $escalate(ScreeningResult::FLAG);
            $reasons[] = 'toxic_domain';
            $scores['toxic_domain'] = true;
        }

        // 3. StopForumSpam (live → cached → no-signal). High confidence blocks; borderline flags.
        if ($reg['stopforumspam']['enabled'] ?? true) {
            // The live-API enablement is the operator's documented setting (antispam.sfs_use_api) via
            // ExternalSignalPolicy — so toggling it in the ACP actually controls the live call (previously the
            // raw config did, and the setting was inert). The fail-safe holds: with the live API OFF the cached
            // StopForumSpam + disposable + ban checks still run, and an ON-but-down API still FLAGs on degrade.
            $sfs = $this->sfs->check($ip, $email, $username, app(ExternalSignalPolicy::class)->apiEnabled());
            $scores['stopforumspam'] = $sfs;

            if ($sfs['listed']) {
                // Admin-tunable block threshold (Phase 4 · M6.3) — DB setting → config → 75.
                $threshold = app(ExternalSignalPolicy::class)->confidenceThreshold();
                $escalate(($sfs['confidence'] ?? 0) >= $threshold ? ScreeningResult::BLOCK : ScreeningResult::FLAG);
                $reasons[] = 'stopforumspam';
            }

            if ($sfs['degraded']) {
                $degraded = true;
                // Fail SAFE, not open (phase-1.5 F-C): the live API was meant to run but is unreachable and
                // nothing is cached — FLAG for moderation instead of silently allowing. Honours flag-don't-
                // block (the account is held/pending, never hard-blocked on a mere degrade).
                if (! $sfs['listed']) {
                    $escalate(ScreeningResult::FLAG);
                    $reasons[] = 'stopforumspam_degraded';
                }
            }
        }

        // 4. IP registration velocity (local) → flag a spike, never block (could be a shared NAT/office IP).
        if ($ip !== '' && $this->velocityExceeded($ip, (int) ($reg['velocity']['per_ip_per_hour'] ?? 5))) {
            $escalate(ScreeningResult::FLAG);
            $reasons[] = 'velocity';
            $scores['velocity'] = true;
        }

        $result = new ScreeningResult($decision, $scores, $degraded, $reasons);
        $this->record($ip, $email, $username, $result);

        return $result;
    }

    private function banned(string $ip, string $email): bool
    {
        if ($ip === '' && $email === '') {
            return false;
        }

        return Ban::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($ip, $email) {
                if ($ip !== '') {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'ip')->where('value', $ip));
                }
                if ($email !== '') {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'email')->where('value', $email));
                }
            })
            ->exists();
    }

    private function disposable(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }
        $domain = strtolower(Str::after($email, '@'));

        return $domain !== '' && BlocklistEntry::query()->live()
            ->where('source', 'disposable')->where('type', 'email_domain')->where('value', $domain)->exists();
    }

    /** A domain on the cron-warmed StopForumSpam toxic-domains list (phase-1.5 F-C, novfora:antispam:warm). */
    private function toxicDomain(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }
        $domain = strtolower(Str::after($email, '@'));

        return $domain !== '' && BlocklistEntry::query()->live()
            ->where('source', 'stopforumspam')->where('type', 'email_domain')->where('value', $domain)->exists();
    }

    private function velocityExceeded(string $ip, int $perHour): bool
    {
        if ($perHour <= 0) {
            return false;
        }

        return RegistrationCheck::where('ip_address', $ip)
            ->where('created_at', '>', now()->subHour())
            ->count() >= $perHour;
    }

    private function record(string $ip, string $email, string $username, ScreeningResult $result): void
    {
        RegistrationCheck::create([
            'ip_address' => $ip ?: null,
            'email' => $email ?: null,
            'username' => $username ?: null,
            'provider_scores' => $result->scores,
            'decision' => $result->decision,
            'degraded' => $result->degraded,
            'created_at' => now(),
        ]);
    }
}
