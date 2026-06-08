<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * Every registered GET admin page that has no required route parameters — i.e. every directly-loadable
 * ACP page. The authz walk asserts the whole surface is gated, so a new page added without the gate is
 * caught here (the kickoff's "walk EVERY /admin route as non-admin → denied").
 *
 * @return list<string>
 */
function acpGetPages(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r): bool => str_starts_with($r->uri(), 'admin'))
        ->filter(fn (RoutingRoute $r): bool => in_array('GET', $r->methods(), true))
        ->filter(fn (RoutingRoute $r): bool => ! str_contains($r->uri(), '{'))
        ->map(fn (RoutingRoute $r): string => '/'.ltrim($r->uri(), '/'))
        ->unique()->values()->all();
}

it('exposes a non-trivial admin surface to walk', function () {
    expect(count(acpGetPages()))->toBeGreaterThanOrEqual(6);
});

it('forbids every admin page to a signed-in non-admin (403)', function () {
    $member = Users::inGroups(['members']);

    foreach (acpGetPages() as $uri) {
        $this->actingAs($member)->get($uri)->assertForbidden("expected 403 for member on {$uri}");
    }
});

it('redirects a guest from every admin page to login', function () {
    foreach (acpGetPages() as $uri) {
        $this->get($uri)->assertRedirect(route('login'));
    }
});

it('bounces a staff admin without confirmed 2FA to the 2FA setup page', function () {
    $admin = Users::inGroups(['admins']); // has admin.access, but no confirmed authenticator

    foreach (acpGetPages() as $uri) {
        $this->actingAs($admin)->get($uri)->assertRedirect(route('settings.two-factor'));
    }
});

it('admits an admin with admin.access + confirmed 2FA to the dashboard', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('System health');
});
