<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The ACP webhooks page (ADR-0033). Admins-only (admin.access + 2FA), self-guarded in mount() and every action.
| A bad URL surfaces as an inline error (the SSRF guard), not a 500.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('blocks non-admins and 2FA-less admins from the webhooks page (403)', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.webhooks')->assertStatus(403);

    $this->actingAs(Users::inGroups(['moderators']));
    Livewire::test('admin.webhooks')->assertStatus(403);

    $this->actingAs(Users::inGroups(['admins'])); // no 2FA
    Livewire::test('admin.webhooks')->assertStatus(403);
});

it('lets a 2FA admin create, toggle and remove an endpoint', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.webhooks')
        ->set('url', 'https://hooks.example.test/in')
        ->set('events', ['post.created'])
        ->call('create')
        ->assertHasNoErrors();

    $endpoint = WebhookEndpoint::firstOrFail();
    expect($endpoint->is_active)->toBeTrue()->and($endpoint->events)->toBe(['post.created']);

    Livewire::test('admin.webhooks')->call('toggle', $endpoint->id);
    expect($endpoint->fresh()->is_active)->toBeFalse();

    Livewire::test('admin.webhooks')->call('remove', $endpoint->id);
    expect(WebhookEndpoint::find($endpoint->id))->toBeNull();
});

it('surfaces the SSRF refusal as an inline error, not a 500', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.webhooks')
        ->set('url', 'http://127.0.0.1/in')
        ->set('events', ['post.created'])
        ->call('create')
        ->assertStatus(200)
        ->assertSee('loopback');
    expect(WebhookEndpoint::count())->toBe(0);
});
