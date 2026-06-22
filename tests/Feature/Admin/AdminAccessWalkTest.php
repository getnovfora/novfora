<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * A 2FA admin crowned a CO-OWNER (the operator the installer crowns): holds admin.security.access + the
 * is_co_owner flag, so they can render the WHOLE panel — including the v3-a Security section, which gates on
 * admin.security.access. A plain admin 403s there by design (covered by the deny-walk).
 */
function walkCoOwner(): User
{
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $adminsId = (int) Group::query()->where('slug', 'admins')->value('id');
    $admin->groups()->updateExistingPivot($adminsId, ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $admin->id,
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $admin->fresh();
}

/**
 * Every registered GET admin page that has no required route parameters — i.e. every directly-loadable
 * ACP page. The authz walk asserts the whole surface is gated, so a new page added without the gate is
 * caught here (the kickoff's "walk EVERY /admin route as non-admin → denied"). Its mirror — that a real
 * admin can RENDER each of these (200, no exception) — lives at the bottom of this file (ACP v1.1).
 *
 * @return list<string>
 */
function acpGetPages(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r): bool => str_starts_with($r->uri(), 'admin'))
        ->filter(fn (RoutingRoute $r): bool => in_array('GET', $r->methods(), true))
        ->filter(fn (RoutingRoute $r): bool => ! str_contains($r->uri(), '{'))
        // ACP v3 (v3-h): the OLD admin URLs are now bare 301 redirects to their new section homes — not pages,
        // so they're excluded from the gate/render walks below and covered by the dedicated 301 test instead.
        ->filter(fn (RoutingRoute $r): bool => ! str_contains((string) $r->getActionName(), 'RedirectController'))
        ->map(fn (RoutingRoute $r): string => '/'.ltrim($r->uri(), '/'))
        ->unique()->values()->all();
}

/**
 * The Moderator Control Panel (MCP) pages — every parameterless GET route named `moderation.*` (dashboard,
 * approval queue, reports, recycle bin). Enumerated by route NAME (not URI) so `/recycle-bin`, whose name is
 * `moderation.recycle-bin`, is included. Walked as part of the admin-render mirror below.
 *
 * @return list<string>
 */
function mcpGetPages(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r): bool => str_starts_with((string) $r->getName(), 'moderation.'))
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

// ── The admin-render mirror (ACP v1.1) ─────────────────────────────────────────────────────────────────
// The deny-walk above proves NON-admins are blocked from every admin page. It says nothing about whether an
// AUTHENTICATED admin can actually RENDER each page — the exact blind spot that let `admin.settings.registration`
// ship a 500 ("Too few arguments to ...::gates(), 0 passed") that no guests-denied test could see. These
// mirror tests visit every directly-loadable page as a real 2FA'd admin and assert a clean 200, catching the
// whole "renders fine for guests-denied but 500s for admins" class. (This test FAILS on the unpatched
// registration page and PASSES once `gates()` no longer demands an un-injectable argument.)

it('renders every admin page (ACP + system) for a 2FA co-owner with no exception', function () {
    // The operator is a CO-OWNER (v3-a): holds admin.security.access + every section key, so they render the
    // WHOLE panel including the Security section. A plain admin 403s on the Security pages by design — the
    // deny-walk above + the dedicated per-section gating test cover that.
    $admin = walkCoOwner();

    $pages = acpGetPages();
    expect($pages)->toContain('/admin/settings/registration'); // the page that shipped the 500 — must be walked
    expect($pages)->toContain('/admin/security/co-owners');     // the v3-a Security pages must be walked too
    expect($pages)->toContain('/admin/members/all');            // the ACP v4 member directory table (A1)
    expect($pages)->toContain('/admin/moderation/warning-types'); // ACP v4 A3 — warning-type CRUD
    expect($pages)->toContain('/admin/moderation/canned-replies'); // T1 — canned-reply CRUD

    foreach ($pages as $uri) {
        $this->actingAs($admin)->get($uri)
            ->assertOk("admin page {$uri} should render 200 for a 2FA co-owner (no exception)")
            // …and INSIDE the app layout — a full <html> document, not a bare <x-admin.shell> fragment. BUG-001:
            // a view that emits the shell with no @extends('layouts.app')/@section('content') envelope returns
            // 200 but renders only the shell — its first icon <svg> unconstrained, no page chrome. assertOk()
            // alone can't see that; the presence of <html proves the layout actually ran.
            ->assertSee('<html', false);
    }
});

it('renders the per-forum admin pages (permissions + moderators) with full chrome for a 2FA admin', function () {
    // These two ACP pages take a {forum} param, so the parameterless walk above skips them — yet they are the
    // exact BUG-001 shape (a bare <x-admin.shell> with no layout envelope). Walk them explicitly. A plain (non
    // -co-owner) full admin renders them: they are Forums-section pages, not Security, so the administrator
    // preset's section keys admit them — which also pins that a non-co-owner admin keeps non-Security reach.
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    foreach ([
        route('admin.forums.permissions', $forum),
        route('admin.forums.moderators', $forum),
    ] as $url) {
        $this->actingAs($admin)->get($url)
            ->assertOk("{$url} should render 200 for a 2FA admin")
            ->assertSee('<html', false); // full layout document, not a bare shell fragment (BUG-001)
    }
});

it('renders every moderator control panel (MCP) page for a 2FA admin', function () {
    // The MCP pages gate on moderator capabilities (bans.manage, topic.moderate); an admin renders them
    // because the administrator preset COMPOSES the moderator preset (RoleSeeder). If that composition ever
    // changes, this test (not the registration guard) is what flags it.
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $pages = mcpGetPages();
    expect(count($pages))->toBeGreaterThanOrEqual(4); // dashboard, queue, reports, recycle-bin

    foreach ($pages as $uri) {
        $this->actingAs($admin)->get($uri)
            ->assertOk("MCP page {$uri} should render 200 for a 2FA admin (no exception)");
    }
});
