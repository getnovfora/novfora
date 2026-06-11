<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\BlocklistEntry;
use Illuminate\Support\Facades\Http;

/**
 * StopForumSpam-style crowdsourced blocklist check (ADR-0007 §2.2). Best-effort live API, warmed into and
 * backed by the cron-cached `blocklist_cache` table, degrading cleanly: live → cache → no-signal. It NEVER
 * throws — a dead/slow API resolves to the cached answer (or "no signal", which the guard treats as allow,
 * flag-don't-block). This is what makes registration not hard-depend on an external service.
 */
final class StopForumSpamClient
{
    private const API = 'https://api.stopforumspam.org/api';

    private const CACHE_TTL_DAYS = 7;

    /**
     * @return array{listed:bool, confidence:?int, degraded:bool, source:string}
     */
    public function check(string $ip, string $email, string $username): array
    {
        $cfg = (array) config('novfora.antispam.registration.stopforumspam', []);

        if (($cfg['use_api'] ?? true) === true) {
            $live = $this->fromApi($ip, $email, $username, (int) ($cfg['timeout'] ?? 4));
            if ($live !== null) {
                $this->cacheListing($ip, $email, $live);

                return $live + ['degraded' => false, 'source' => 'api'];
            }
        }

        // API disabled or unreachable → fall back to the cron-cached blocklist.
        $cached = $this->fromCache($ip, $email);
        if ($cached !== null) {
            return $cached + ['degraded' => true, 'source' => 'cache'];
        }

        // Nothing live, nothing cached → no signal (the guard reads this as allow). Never an error.
        return ['listed' => false, 'confidence' => null, 'degraded' => ($cfg['use_api'] ?? true) === true, 'source' => 'none'];
    }

    /**
     * @return array{listed:bool, confidence:?int}|null null = API unreachable/failed → caller degrades
     */
    private function fromApi(string $ip, string $email, string $username, int $timeout): ?array
    {
        $params = array_filter(['ip' => $ip, 'email' => $email, 'username' => $username], fn ($v) => $v !== '');
        if ($params === []) {
            return ['listed' => false, 'confidence' => null]; // nothing to check ≠ API failure
        }
        $params['json'] = true;

        try {
            $resp = Http::timeout($timeout)->connectTimeout(2)->get(self::API, $params);
            if (! $resp->ok()) {
                return null;
            }
            $data = $resp->json();
            if (! is_array($data) || ($data['success'] ?? 0) != 1) {
                return null;
            }

            $listed = false;
            $confidence = 0;
            foreach (['ip', 'email', 'username'] as $field) {
                if (($data[$field]['appears'] ?? 0) == 1) {
                    $listed = true;
                    $confidence = max($confidence, (int) round((float) ($data[$field]['confidence'] ?? 0)));
                }
            }

            return ['listed' => $listed, 'confidence' => $listed ? $confidence : null];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{listed:bool, confidence:?int}|null
     */
    private function fromCache(string $ip, string $email): ?array
    {
        if ($ip === '' && $email === '') {
            return null;
        }

        $entry = BlocklistEntry::query()->live()->where('source', 'stopforumspam')
            ->where(function ($q) use ($ip, $email) {
                if ($ip !== '') {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'ip')->where('value', $ip));
                }
                if ($email !== '') {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'email')->where('value', $email));
                }
            })
            ->orderByDesc('confidence')
            ->first();

        return $entry ? ['listed' => true, 'confidence' => (int) $entry->confidence] : null;
    }

    /** @param array{listed:bool, confidence:?int} $live */
    private function cacheListing(string $ip, string $email, array $live): void
    {
        if (! $live['listed']) {
            return; // only positive listings are cached, so the degrade path errs toward allow
        }

        $confidence = (int) ($live['confidence'] ?? 0);
        foreach (['ip' => $ip, 'email' => $email] as $type => $value) {
            if ($value === '') {
                continue;
            }
            BlocklistEntry::updateOrCreate(
                ['type' => $type, 'value' => $value, 'source' => 'stopforumspam'],
                ['confidence' => $confidence, 'expires_at' => now()->addDays(self::CACHE_TTL_DAYS)],
            );
        }
    }
}
