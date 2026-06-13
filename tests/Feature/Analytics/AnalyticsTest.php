<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Analytics\AnalyticsService;
use App\Forum\PostService;
use App\Models\DailyMetric;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Admin analytics (ADR-0035): privacy-conscious AGGREGATE rollups (no PII), idempotent, baseline-cron driven,
| with an admins-only dashboard.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('rolls up aggregate daily metrics and is idempotent', function () {
    $today = now();
    $u1 = Users::inGroups(['members', 'tl1']);
    $u2 = Users::inGroups(['members', 'tl1']);
    $u1->forceFill(['last_active_at' => now()])->saveQuietly();

    $forum = Forum::create(['slug' => 'a', 'title' => 'A', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($u1, $forum, 'T', 'markdown', ['source' => 'op']);
    app(PostService::class)->reply($u2, $topic, 'markdown', ['source' => 'r']);

    app(AnalyticsService::class)->rollup($today);

    $val = fn (string $k): int => (int) DailyMetric::where('metric_date', $today->toDateString())->where('metric_key', $k)->value('value');
    expect($val('users_new'))->toBe(2)
        ->and($val('users_total'))->toBe(2)
        ->and($val('topics_new'))->toBe(1)
        ->and($val('posts_new'))->toBeGreaterThanOrEqual(2)   // opening post + reply
        ->and($val('active_users'))->toBeGreaterThanOrEqual(1);

    // Idempotent: a re-run overwrites, leaving exactly one row per (date, key).
    app(AnalyticsService::class)->rollup($today);
    expect(DailyMetric::where('metric_key', 'users_new')->count())->toBe(1);
});

it('the rollup command computes the recent window', function () {
    Users::inGroups(['members', 'tl1']);

    $this->artisan('novfora:analytics:rollup')->assertSuccessful();
    expect(DailyMetric::where('metric_date', now()->toDateString())->where('metric_key', 'users_total')->exists())->toBeTrue();
});

it('gates the analytics dashboard and shows aggregates to a 2FA admin', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.analytics')->assertStatus(403);

    $this->actingAs(Users::inGroups(['admins'])); // no 2FA confirmed
    Livewire::test('admin.analytics')->assertStatus(403);

    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);
    Livewire::test('admin.analytics')
        ->assertStatus(200)
        ->assertSee('Members')
        ->call('refresh')
        ->assertHasNoErrors();
    expect(DailyMetric::count())->toBeGreaterThan(0);
});
