<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Models\Ban;
use App\Support\Net\CidrMatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Enforces ip / range bans (U13 / NOV-111, ADR-0121). Before this, `type=range` bans were stored but matched
 * nowhere and `type=ip` was an exact-string match only. This is the single authority for "is this address
 * banned?", used at the abuse boundaries (registration; extendable to login/post). The active ip+range ban
 * list is cached for a short TTL so it is NOT a per-request DB hit on the baseline tier; the cache is dropped
 * whenever a ban is created/lifted (Ban::booted bumps AclVersion, and the IpBanService invalidates here).
 */
final class IpBanGuard
{
    private const CACHE_KEY = 'novfora:ip-bans:active';

    private const TTL_SECONDS = 60;

    /** Is $ip covered by any live exact-ip or CIDR/range ban? */
    public function isBanned(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        ['ip' => $exact, 'range' => $ranges] = $this->activeBans();

        // Exact ip match is O(1) via the set; range match walks the (small) CIDR list.
        return isset($exact[$ip]) || CidrMatcher::matchesAny($ip, $ranges);
    }

    /** Drop the cached active-ban list (called by IpBanService on every ip/range ban write). */
    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The live ip + range ban values, cached. Exact ips are keyed for O(1) lookup; ranges are a list of
     * CIDR strings for CidrMatcher. Expired bans are excluded at query time.
     *
     * @return array{ip:array<string,true>, range:list<string>}
     */
    private function activeBans(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['ip'], $cached['range'])) {
                return $cached;
            }

            $rows = Ban::query()
                ->whereIn('type', ['ip', 'range'])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get(['type', 'value']);

            $out = ['ip' => [], 'range' => []];
            foreach ($rows as $row) {
                $value = trim((string) $row->value);
                if ($value === '') {
                    continue;
                }
                if ($row->type === 'ip') {
                    $out['ip'][$value] = true;
                } else {
                    $out['range'][] = $value;
                }
            }
            Cache::put(self::CACHE_KEY, $out, self::TTL_SECONDS);

            return $out;
        } catch (\Throwable) {
            return ['ip' => [], 'range' => []]; // pre-install / cache down — fail open on THIS check only
        }
    }
}
