<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Api;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues, resolves, and revokes personal API tokens (ADR-0033). The plaintext is generated here, returned ONCE
 * to the caller, and persisted only as a sha256 hash — it is never stored or logged in the clear and cannot be
 * recovered. Resolution looks a token up by its hash (an indexed unique column, like Sanctum), rejects an
 * expired token, and rejects a token whose owner is no longer active — so a banned/suspended user's tokens
 * stop working immediately.
 */
final class ApiTokenService
{
    private const PREFIX = 'nvf_';

    /** Admin-scoped tokens carry a DISTINCT prefix so secret-scanners can flag a leaked admin key specifically. */
    private const ADMIN_PREFIX = 'nvfa_';

    /**
     * Issue a new token for a user. Returns the model AND the one-time plaintext to show the user once. A token
     * with admin scopes (E1) gets the `nvfa_` prefix and stores its scope list + optional ip allowlist; a plain
     * member token (no scopes) keeps the `nvf_` prefix and acts fully as its user.
     *
     * @param  list<string>  $scopes  admin scope strings (already validated/sanitised by the caller)
     * @param  list<string>|null  $ipAllowlist  exact IPs the token may present from (null = any)
     * @return array{token: ApiToken, plaintext: string}
     */
    public function issue(User $user, string $name, ?Carbon $expiresAt = null, array $scopes = [], ?array $ipAllowlist = null): array
    {
        $scopes = ApiScopes::sanitize($scopes);
        $plaintext = ($scopes === [] ? self::PREFIX : self::ADMIN_PREFIX).Str::random(48);
        $token = ApiToken::create([
            'user_id' => $user->getKey(),
            'name' => $name,
            'token_hash' => $this->hash($plaintext),
            'scopes' => $scopes === [] ? null : $scopes,
            'ip_allowlist' => $ipAllowlist === null || $ipAllowlist === [] ? null : $ipAllowlist,
            'expires_at' => $expiresAt,
        ]);
        Audit::log('api_token.created', $token, ['name' => $name, 'scopes' => $scopes]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /** Resolve a presented bearer token to a usable ApiToken, or null if invalid/expired/owner-inactive. */
    public function resolve(string $plaintext): ?ApiToken
    {
        if ($plaintext === '') {
            return null;
        }
        $token = ApiToken::query()->where('token_hash', $this->hash($plaintext))->first();
        if (! $token instanceof ApiToken) {
            return null;
        }
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }
        $user = $token->user;
        if (! $user instanceof User || ($user->status ?? 'active') !== 'active') {
            return null;
        }

        return $token;
    }

    public function revoke(ApiToken $token): void
    {
        Audit::log('api_token.revoked', $token, ['name' => $token->name]);
        $token->delete();
    }

    /**
     * Stamp `last_used_at`, throttled to at most once per 5 minutes per token (Cache::add is atomic), so a busy
     * automation caller doesn't write a row on every request — mirroring the ThrottledLastActive discipline.
     */
    public function markUsed(ApiToken $token): void
    {
        if (Cache::add('api-token-used:'.$token->getKey(), 1, now()->addMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
