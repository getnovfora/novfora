<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Jobs\ReindexSearch;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M4.1 — Admin → Settings → Search. Staff-gated; refuses to strand search on an unreachable
| Meilisearch; stores the API key encrypted; queues a cron-drained reindex.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function searchAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'search-admin@acp.test']));
}

it('forbids a non-admin from the search settings page', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'search-member@acp.test']);

    $this->actingAs($member)->get(route('admin.settings.search'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.settings.search')->assertStatus(403);
});

it('lets an admin save the Meilisearch host while staying on the database driver', function () {
    Livewire::actingAs(searchAdmin())
        ->test('admin.settings.search')
        ->set('driver', 'database')
        ->set('host', 'http://meili.internal:7700')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->string('search.driver'))->toBe('database');
    expect(app(Settings::class)->string('search.meilisearch_host'))->toBe('http://meili.internal:7700');
});

it('switches to meilisearch only when the host responds, and stores the key encrypted', function () {
    Http::fake(['*/health' => Http::response('{"status":"available"}', 200)]);

    Livewire::actingAs(searchAdmin())
        ->test('admin.settings.search')
        ->set('driver', 'meilisearch')
        ->set('host', 'http://meili.up:7700')
        ->set('key', 'super-secret-key')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(Settings::class);
    expect($settings->string('search.driver'))->toBe('meilisearch');
    // The key is set but never stored in plaintext.
    expect($settings->secretIsSet('search.meilisearch_key'))->toBeTrue();
    $raw = Setting::where('key', 'search.meilisearch_key')->value('value');
    expect($raw)->not->toBe('super-secret-key');
});

it('refuses to switch to meilisearch when the host is unreachable (search stays on the database)', function () {
    Http::fake(['*/health' => Http::response('', 503)]);

    Livewire::actingAs(searchAdmin())
        ->test('admin.settings.search')
        ->set('driver', 'meilisearch')
        ->set('host', 'http://meili.down:7700')
        ->call('save')
        ->assertSee('did not respond');

    // The driver was NOT switched — the board is never stranded on an unreachable engine.
    expect(app(Settings::class)->string('search.driver'))->toBe('database');
});

it('queues a cron-drained reindex job', function () {
    Queue::fake();

    Livewire::actingAs(searchAdmin())
        ->test('admin.settings.search')
        ->call('reindex')
        ->assertHasNoErrors();

    Queue::assertPushed(ReindexSearch::class);
});
