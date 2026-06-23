<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\DailyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| T3 — analytics trend charts (hand-authored inline SVG). The chart renders from the dense 30-day metric series;
| empty + short-range states render gracefully (flat baseline, no error); the data table stays as the a11y
| equivalent (the SVGs are aria-hidden).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function analyticsAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'])); // admin.access + admin.analytics.access
}

it('renders trend charts from the metric series (a populated series draws a polyline)', function () {
    DailyMetric::create(['metric_date' => now()->subDays(3)->toDateString(), 'metric_key' => 'users_new', 'value' => 4]);
    DailyMetric::create(['metric_date' => now()->subDays(1)->toDateString(), 'metric_key' => 'users_new', 'value' => 7]);

    Livewire::actingAs(analyticsAdmin())->test('admin.analytics')
        ->assertSeeHtml('analytics-chart-users_new')
        ->assertSeeHtml('analytics-chart-active_users')
        ->assertSeeHtml('polyline'); // a non-empty series draws the line
});

it('keeps the data table as the accessible equivalent (charts are aria-hidden)', function () {
    DailyMetric::create(['metric_date' => now()->toDateString(), 'metric_key' => 'posts_new', 'value' => 2]);

    Livewire::actingAs(analyticsAdmin())->test('admin.analytics')
        ->assertSeeHtml('aria-hidden="true"')   // the decorative chart SVG
        ->assertSeeHtml('analytics-row');        // the accessible table row
});

it('renders gracefully with no metrics (flat baseline, no error)', function () {
    Livewire::actingAs(analyticsAdmin())->test('admin.analytics')
        ->assertSeeHtml('analytics-chart-posts_new')      // the chart card still renders
        ->assertSee('No analytics yet')                   // the table empty-state (x-ui.empty title)
        ->assertSee('They build up daily — or click Refresh.'); // ... and its supporting copy
});

it('renders with a single day of data (short range)', function () {
    DailyMetric::create(['metric_date' => now()->toDateString(), 'metric_key' => 'topics_new', 'value' => 3]);

    Livewire::actingAs(analyticsAdmin())->test('admin.analytics')->assertSeeHtml('analytics-chart-topics_new');
});

it('forbids a non-admin from the analytics component', function () {
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.analytics')->assertForbidden();
});
