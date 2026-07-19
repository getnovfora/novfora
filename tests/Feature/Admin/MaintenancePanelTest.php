<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Jobs\RebuildCountersJob;
use App\Maintenance\CounterRebuildService;
use App\Models\Forum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U16 (NOV-114) — ACP System → Maintenance: caches, counter self-heal (queued), redacted log tail, mail test.
| Gated at admin.system.access + staff-2FA, re-asserted in the component.
*/

uses(RefreshDatabase::class);

it('gates the maintenance page behind admin.system.access + staff-2FA (403 for a plain member)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.maintenance')->assertStatus(403);
});

it('clears the compiled caches for a 2FA admin', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.maintenance')
        ->call('clearCaches')
        ->assertHasNoErrors()
        ->assertSet('messageVariant', 'success')
        ->assertSee('Configuration, route, and event caches cleared');
});

it('queues a counter rebuild job rather than blocking the request', function () {
    $this->seed();
    Queue::fake();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.maintenance')->call('rebuildCounters')->assertHasNoErrors();

    Queue::assertPushed(RebuildCountersJob::class);
});

it('shows the log tail but redacts every secret shape (U16 apex — 2 HIGH + MEDIUM)', function () {
    $this->seed();
    // One line per secret shape so each redaction rule is exercised in isolation (an Authorization line masks to
    // EOL, so mixing shapes on one line would pass trivially).
    $log = implode("\n", [
        '[2026-07-19 00:00:00] testing.ERROR: MAINT-LOG-MARKER a plain diagnostic line',
        'Authorization: Bearer eyJhdr.PAYLOADSECRETXYZ.SIGSECRETXYZ',
        'stripe charge failed for sk_live_ABCDEFGHIJKLMNOP0123456789 key',
        'github push with ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345 pat',
        'aws call with AKIAIOSFODNN7EXAMPLE id',
        'PDOException connecting to mysql://forum:DBPASSWORDLEAK@db:3306/novfora refused',
        'Set-Cookie: laravel_session=SESSIONCOOKIELEAKVALUE; path=/',
        'config dump password=PLAINPASSWORDLEAK loaded',
    ])."\n";
    file_put_contents(storage_path('logs/laravel.log'), $log, FILE_APPEND);

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.maintenance')
        ->assertSee('MAINT-LOG-MARKER')          // the ordinary line is shown
        ->assertSee('[redacted]')
        ->assertDontSee('PAYLOADSECRETXYZ')      // JWT / Authorization header
        ->assertDontSee('sk_live_ABCDEFGHIJKLMNOP')
        ->assertDontSee('ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ')
        ->assertDontSee('AKIAIOSFODNN7EXAMPLE')
        ->assertDontSee('DBPASSWORDLEAK')        // DSN inline credential
        ->assertDontSee('SESSIONCOOKIELEAKVALUE') // session cookie
        ->assertDontSee('PLAINPASSWORDLEAK');    // key=value secret
});

it('heals the forum last-post pointer from repaired topic data in a single rebuildAll pass (U16 apex MEDIUM)', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'rebuild', 'title' => 'Rebuild', 'type' => 'forum']);
    $op = Users::inGroups(['members', 'tl2'], ['username' => 'rb-op', 'email' => 'rb-op@t.test']);
    $topic = app(PostService::class)->createTopic($op, $forum, 'Rebuild topic', 'markdown', ['source' => 'op']);
    $realPostId = (int) $topic->fresh()->last_post_id;
    expect($realPostId)->toBeGreaterThan(0);

    // Drift the denormalised pointers (bypass observers) so BOTH topic and forum are wrong.
    $topic->forceFill(['last_post_id' => 999999, 'last_posted_at' => '2000-01-01 00:00:00'])->saveQuietly();
    $forum->forceFill(['last_post_id' => 999999, 'last_posted_at' => '2000-01-01 00:00:00'])->saveQuietly();

    app(CounterRebuildService::class)->rebuildAll();

    // Topics recompute BEFORE forums, so the forum reads the repaired topic pointer — both converge in ONE pass.
    expect((int) $topic->fresh()->last_post_id)->toBe($realPostId)
        ->and((int) $forum->fresh()->last_post_id)->toBe($realPostId);
});

it('sends a mail self-test and surfaces the result', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.maintenance')
        ->set('testTo', 'ops@example.com')
        ->call('sendTest')
        ->assertHasNoErrors()
        ->assertSet('messageVariant', 'success')
        ->assertSee('Test email sent');
});

it('recomputes a drifted user post_count via the command (idempotent self-heal)', function () {
    $this->seed();
    $user = User::factory()->create(['post_count' => 999]); // no real posts → should heal to 0

    $this->artisan('novfora:forums:recompute-counters')->assertExitCode(0);

    expect($user->fresh()->post_count)->toBe(0);
});
