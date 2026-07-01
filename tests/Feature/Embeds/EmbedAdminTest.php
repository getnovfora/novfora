<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Embeds\EmbedManager;
use App\Models\AuditLog;
use App\Models\EmbedSite;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U7 (ADR-0103) — the ACP Embeds page: authz (admin.access + staff-2FA, re-asserted inside Livewire because
| /livewire/update bypasses route middleware), the audited site lifecycle, origin validation, and the
| feature master switch.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function embedAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

it('gates the route and the livewire component', function () {
    $this->get(route('admin.embeds'))->assertRedirect(route('login'));
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.embeds'))->assertForbidden();

    Livewire::actingAs(Users::inGroups(['members']))->test('admin.embeds')->assertForbidden();
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.embeds')->assertForbidden(); // staff without 2FA

    $this->actingAs(embedAdmin())->get(route('admin.embeds'))->assertOk()->assertSee('Embed widgets');
});

it('registers a site with a normalized origin and shows the key once', function () {
    Livewire::actingAs(embedAdmin())->test('admin.embeds')
        ->set('name', 'Marketing site')
        ->set('origin', 'HTTPS://Partner.Example:8443')
        ->call('create')
        ->assertSet('error', null);

    $site = EmbedSite::firstOrFail();
    expect($site->origin)->toBe('https://partner.example:8443')
        ->and($site->key)->toStartWith('emb_')
        ->and(strlen($site->key))->toBe(44)
        ->and(AuditLog::where('action', 'embed_site.created')->exists())->toBeTrue();
});

it('refuses origins that could widen the grant', function () {
    $component = Livewire::actingAs(embedAdmin())->test('admin.embeds');

    foreach ([
        'javascript://alert(1)',
        'https://partner.example/path',
        'https://partner.example?q=1',
        'https://user:pass@partner.example',
        '*',
        'https://*',
        'not a url',
        'ftp://partner.example',
        '',
    ] as $bad) {
        $component->set('name', 'Bad')->set('origin', $bad)->call('create');
        expect($component->get('error'))->not->toBeNull();
    }

    expect(EmbedSite::count())->toBe(0);
});

it('toggles, rotates, and removes a site through the audited manager', function () {
    $site = app(EmbedManager::class)->create('Partner', 'https://partner.example');
    $oldKey = $site->key;

    $component = Livewire::actingAs(embedAdmin())->test('admin.embeds');

    $component->call('toggle', $site->id);
    expect($site->refresh()->is_enabled)->toBeFalse();

    $component->call('rotate', $site->id);
    expect($site->refresh()->key)->not->toBe($oldKey);
    $rotation = AuditLog::where('action', 'embed_site.key_rotated')->latest('id')->firstOrFail();
    // The audit trail must never carry a usable key — suffix only.
    expect(json_encode($rotation->changes))->not->toContain($site->key)->toContain(substr($site->key, -6));

    $component->call('remove', $site->id);
    expect(EmbedSite::whereKey($site->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'embed_site.removed')->exists())->toBeTrue();
});

it('flips the feature master switch through settings (audited)', function () {
    expect(app(Settings::class)->bool('embeds.enabled'))->toBeFalse();

    Livewire::actingAs(embedAdmin())->test('admin.embeds')->call('toggleFeature');

    expect(app(Settings::class)->bool('embeds.enabled'))->toBeTrue()
        ->and(AuditLog::where('action', 'settings.updated')->exists())->toBeTrue();
});
