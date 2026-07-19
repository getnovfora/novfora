<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Models\Ban;
use App\Support\Audit;
use App\Support\Net\CidrMatcher;
use Illuminate\Support\Carbon;

/**
 * Value-ban authority (U13 / NOV-111, ADR-0121): create/lift ip, range (CIDR), and email bans — the types
 * that had no UI and, for range, no enforcement. Distinct from `UserBanService` (which flips a user's account
 * status and needs the S5 owner-strand guard); a value ban flips no account state, so no owner-strand concern.
 * Every write is audited and invalidates the IpBanGuard cache so enforcement is immediate.
 */
final class IpBanService
{
    public function __construct(private readonly IpBanGuard $guard) {}

    /**
     * Create an ip / range / email ban. For ip/range the value is validated + normalised through CidrMatcher
     * (a bare address becomes an exact /32 or /128); an invalid value throws. Returns the Ban row.
     *
     * @param  string  $type  one of ip | range | email
     */
    public function create(string $type, string $value, ?string $reason, ?Carbon $expiresAt): Ban
    {
        if (! in_array($type, ['ip', 'range', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported ban type.');
        }
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('A ban value is required.');
        }

        if ($type === 'ip' || $type === 'range') {
            $parsed = CidrMatcher::parse($value);
            if ($parsed === null) {
                throw new \InvalidArgumentException('That is not a valid IP address or CIDR range.');
            }
            // A /0 is a "ban every address of this family" catch-all: it would silently disable ALL registration
            // and is almost always a fat-finger. Closing registration is a separate, deliberate setting.
            if ($parsed['prefix'] === 0) {
                throw new \InvalidArgumentException('A /0 range would ban every address. To close registration, use the registration setting instead.');
            }
            // Classify by the PARSED prefix, not a string suffix: a single-address prefix (/32 v4, /128 v6) is an
            // exact ip ban; any broader prefix — including an IPv6 /32 — is a range the guard must walk. Store the
            // CANONICAL form so equivalent IPv6 spellings resolve to one ban target.
            if ($parsed['isHost']) {
                $type = 'ip';
                $value = $parsed['address'];
            } else {
                $type = 'range';
                $value = $parsed['cidr'];
            }
        } elseif ($type === 'email') {
            // RegistrationGuard lowercases the incoming email before the exact-match lookup, so a mixed-case
            // ban value ('Spammer@X.test') would never enforce — store it lowercased to match.
            $value = strtolower($value);
        }

        $ban = Ban::create([
            'type' => $type,
            'value' => $value,
            'scope_type' => 'global',
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);

        $this->guard->invalidate();
        Audit::log('ban.created', $ban, ['type' => $type, 'value' => $value]);

        return $ban;
    }

    /** Lift a value ban (ip/range/email only — user bans go through UserBanService). */
    public function lift(Ban $ban): void
    {
        if (! in_array($ban->type, ['ip', 'range', 'email'], true)) {
            throw new \InvalidArgumentException('Use UserBanService to lift a user ban.');
        }
        $ban->delete();
        $this->guard->invalidate();
        Audit::log('ban.lifted', null, ['type' => $ban->type, 'value' => $ban->value]);
    }

    /** @return list<Ban> the live value bans (ip/range/email), newest first */
    public function active(): array
    {
        return Ban::query()
            ->whereIn('type', ['ip', 'range', 'email'])
            ->orderByDesc('id')
            ->get()->all();
    }
}
