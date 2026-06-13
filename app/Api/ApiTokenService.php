<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Api;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
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

    /**
     * Issue a new token for a user. Returns the model AND the one-time plaintext to show the user once.
     *
     * @return array{token: ApiToken, plaintext: string}
     */
    public function issue(User $user, string $name, ?Carbon $expiresAt = null): array
    {
        $plaintext = self::PREFIX.Str::random(48);
        $token = ApiToken::create([
            'user_id' => $user->getKey(),
            'name' => $name,
            'token_hash' => $this->hash($plaintext),
            'expires_at' => $expiresAt,
        ]);
        Audit::log('api_token.created', $token, ['name' => $name]);

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

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
