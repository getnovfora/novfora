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

        $reg = (array) config('hearth.antispam.registration', []);
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

        // 3. StopForumSpam (live → cached → no-signal). High confidence blocks; borderline flags.
        if ($reg['stopforumspam']['enabled'] ?? true) {
            $sfs = $this->sfs->check($ip, $email, $username);
            $scores['stopforumspam'] = $sfs;
            if ($sfs['degraded']) {
                $degraded = true;
            }

            if ($sfs['listed']) {
                $threshold = (int) ($reg['stopforumspam']['confidence_threshold'] ?? 75);
                $escalate(($sfs['confidence'] ?? 0) >= $threshold ? ScreeningResult::BLOCK : ScreeningResult::FLAG);
                $reasons[] = 'stopforumspam';
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
