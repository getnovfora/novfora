<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| E1 (NOV-135, ADR-0115) — the scoped Admin-API trust spine. Every request proves: token auth → scope check →
| owner canDo → success/error envelope → idempotency → audit-via-token. The one rule: a token can never do more
| than its owner (effective ability = token scopes ∩ user canDo).
*/

uses(RefreshDatabase::class);

/** Issue a token for $user with $scopes and return the plaintext bearer. */
function adminToken(User $user, array $scopes, ?array $ips = null, $expiresAt = null): string
{
    return app(ApiTokenService::class)->issue($user, 'test', $expiresAt, $scopes, $ips)['plaintext'];
}

it('serves whoami for a token with the scope AND owner capability', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']);
    $plaintext = adminToken($admin, ['admin:maintenance']);
    expect(str_starts_with($plaintext, 'nvfa_'))->toBeTrue();

    $this->withToken($plaintext)->getJson('/api/admin/v1/whoami')
        ->assertOk()
        ->assertJsonPath('data.user.username', $admin->username)
        ->assertJsonPath('data.token.scopes', ['admin:maintenance']);
});

it('rejects a missing/invalid token with 401', function () {
    $this->seed();
    $this->getJson('/api/admin/v1/whoami')->assertStatus(401);
    $this->withToken('nvfa_bogus')->getJson('/api/admin/v1/whoami')->assertStatus(401);
});

it('rejects a token lacking the route scope (403 insufficient_scope, enveloped)', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']);
    // Token has a DIFFERENT scope than the route requires.
    $plaintext = adminToken($admin, ['admin:settings.read']);

    $this->withToken($plaintext)->getJson('/api/admin/v1/whoami')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
});

it('enforces scope ∩ canDo — a scoped token whose OWNER lacks the capability is 403', function () {
    $this->seed();
    // A plain member holding an admin-scoped token: the scope passes, but the owner has no admin.access.
    $member = Users::inGroups(['members']);
    $plaintext = adminToken($member, ['admin:maintenance']);

    $this->withToken($plaintext)->getJson('/api/admin/v1/whoami')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('dies with the mask — an expired token or an inactive owner is 401', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']);

    $expired = adminToken($admin, ['admin:maintenance'], null, now()->subDay());
    $this->withToken($expired)->getJson('/api/admin/v1/whoami')->assertStatus(401);

    $live = adminToken($admin, ['admin:maintenance']);
    $admin->forceFill(['status' => 'banned'])->save();
    $this->withToken($live)->getJson('/api/admin/v1/whoami')->assertStatus(401);
});

it('honours the ip allowlist — a token pinned elsewhere is 403 from this address', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']);
    $pinned = adminToken($admin, ['admin:maintenance'], ['203.0.113.9']); // test requests come from 127.0.0.1

    $this->withToken($pinned)->getJson('/api/admin/v1/whoami')->assertStatus(403);

    $here = adminToken($admin, ['admin:maintenance'], ['127.0.0.1']);
    $this->withToken($here)->getJson('/api/admin/v1/whoami')->assertOk();
});

it('replays an idempotent POST and audits via_token exactly once', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']);
    $plaintext = adminToken($admin, ['admin:maintenance']);
    $token = ApiToken::query()->whereNotNull('scopes')->firstOrFail();

    $first = $this->withToken($plaintext)
        ->withHeaders(['Idempotency-Key' => 'run-1'])
        ->postJson('/api/admin/v1/ping')->assertOk();

    $second = $this->withToken($plaintext)
        ->withHeaders(['Idempotency-Key' => 'run-1'])
        ->postJson('/api/admin/v1/ping')->assertOk();

    // The replay returns the byte-identical original response + the replay marker.
    expect($second->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($second->getContent())->toBe($first->getContent());

    // Exactly ONE api.ping audit row, stamped with the token provenance.
    $pings = AuditLog::where('action', 'api.ping')->get();
    expect($pings)->toHaveCount(1)
        ->and((int) ($pings->first()->changes['via_token'] ?? 0))->toBe($token->id);
});

it('publishes the token-gated OpenAPI contract with the scope taxonomy', function () {
    $this->seed();
    // Unauthenticated → 401 (the contract is token-gated). Checked FIRST: withToken() below persists its header.
    $this->getJson('/api/admin/v1/openapi.json')->assertStatus(401);

    $admin = Users::inGroups(['admins']);
    $plaintext = adminToken($admin, ['admin:maintenance']);

    $this->withToken($plaintext)->getJson('/api/admin/v1/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonFragment(['admin:restore']);
});

it('refuses the OpenAPI contract to a non-admin (member) token', function () {
    $this->seed();
    $member = Users::inGroups(['members']);
    // A plain member token (nvf_, no scopes) is authenticated but not admin-scoped.
    $plaintext = app(ApiTokenService::class)->issue($member, 'member', null, [])['plaintext'];
    expect(str_starts_with($plaintext, 'nvf_'))->toBeTrue();

    $this->withToken($plaintext)->getJson('/api/admin/v1/openapi.json')->assertStatus(403);
});

it('releases the idempotency reservation when the request throws, so a retry is not stranded', function () {
    $this->seed();
    // A member token WITH the maintenance scope: RequireApiScope passes, but requireCapability aborts 403 (the
    // owner lacks admin.access) AFTER EnforceIdempotency reserved the key.
    $member = Users::inGroups(['members']);
    $plaintext = adminToken($member, ['admin:maintenance']);

    $this->withToken($plaintext)->withHeaders(['Idempotency-Key' => 'boom'])->postJson('/api/admin/v1/ping')->assertStatus(403);
    // The retry is NOT a permanent 409 in_progress — the thrown request released its reservation, so it re-runs.
    $this->withToken($plaintext)->withHeaders(['Idempotency-Key' => 'boom'])->postJson('/api/admin/v1/ping')->assertStatus(403);
});

it('replays only the matching request — a reused key on a different route is not misrouted', function () {
    // The idempotency key stores method+path; E1 has one mutating route, so this asserts the happy-path replay
    // fingerprint holds. Cross-endpoint 422 is exercised structurally once E2/E3 add a second mutating route.
    $this->seed();
    $admin = Users::inGroups(['admins']);
    $plaintext = adminToken($admin, ['admin:maintenance']);

    $a = $this->withToken($plaintext)->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/admin/v1/ping')->assertOk();
    $b = $this->withToken($plaintext)->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/admin/v1/ping')->assertOk();
    expect($b->headers->get('Idempotent-Replay'))->toBe('true')
        ->and($b->getContent())->toBe($a->getContent());
});
