<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The personal API-token settings SFC (ADR-0033): own-tokens-only. A new token's plaintext is shown once and
| stored only as a hash; revoke is scoped to the signed-in user, so one user can never revoke another's.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('issues a token, shows the plaintext once, and stores only a hash', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $this->actingAs($user);

    Livewire::test('settings.api-tokens')
        ->set('name', 'My script')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('plaintext', fn ($p) => is_string($p) && str_starts_with((string) $p, 'nvf_'));

    $token = ApiToken::where('user_id', $user->id)->firstOrFail();
    expect($token->name)->toBe('My script')
        ->and($token->token_hash)->toHaveLength(64); // sha256 hex — never the plaintext
});

it('lets a user revoke their own token but not another user\'s', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $other = Users::inGroups(['members', 'tl1']);
    $ownerToken = app(ApiTokenService::class)->issue($owner, 'owned')['token'];
    $otherToken = app(ApiTokenService::class)->issue($other, 'theirs')['token'];

    $this->actingAs($owner);
    Livewire::test('settings.api-tokens')->call('revoke', $otherToken->id); // not mine → no-op
    expect(ApiToken::find($otherToken->id))->not->toBeNull();

    Livewire::test('settings.api-tokens')->call('revoke', $ownerToken->id);
    expect(ApiToken::find($ownerToken->id))->toBeNull();
});

it('supports ROTATING a token — issue a replacement, revoke the old, the new one still resolves', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $this->actingAs($user);

    $old = app(ApiTokenService::class)->issue($user, 'ci')['token'];

    // Rotate: issue a replacement through the SFC, capture its one-time plaintext, then revoke the old.
    $component = Livewire::test('settings.api-tokens')->set('name', 'ci (rotated)')->call('create')->assertHasNoErrors();
    $newPlaintext = (string) $component->get('plaintext');
    $component->call('revoke', $old->id);

    expect(ApiToken::find($old->id))->toBeNull()                                       // old revoked
        ->and(app(ApiTokenService::class)->resolve($newPlaintext))->not->toBeNull()    // replacement works
        ->and(app(ApiTokenService::class)->resolve($newPlaintext)?->name)->toBe('ci (rotated)');
});

it('forbids a guest from the token component', function () {
    Livewire::test('settings.api-tokens')->assertForbidden();
});
