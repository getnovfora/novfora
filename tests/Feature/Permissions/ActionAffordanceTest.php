<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\Affordance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\Users;

/**
 * NOV-96 (ADR-0109) — the permission-aware UI contract. The Affordance resolver maps (can, guest, intent) onto
 * exactly one of four in-view outcomes, and the <x-action> component renders each. (The fifth outcome, the
 * route-level friendly-403, is covered by FriendlyDenialTest.)
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── The resolver (pure) ───────────────────────────────────────────────────────────────────────────────────

it('resolves Allow whenever the actor can act, regardless of guest/intent', function () {
    expect(Affordance::resolve(true, isGuest: true, whenDenied: 'disabled', whenGuest: 'cta'))->toBe(Affordance::Allow)
        ->and(Affordance::resolve(true, isGuest: false))->toBe(Affordance::Allow);
});

it('resolves a guest denial to a sign-in CTA or hidden per declared intent', function () {
    expect(Affordance::resolve(false, isGuest: true, whenGuest: 'cta'))->toBe(Affordance::SignInCta)
        ->and(Affordance::resolve(false, isGuest: true, whenGuest: 'hide'))->toBe(Affordance::Hidden)
        ->and(Affordance::resolve(false, isGuest: true))->toBe(Affordance::Hidden); // default hide
});

it('resolves an authenticated denial to disabled-with-reason or hidden per declared intent', function () {
    expect(Affordance::resolve(false, isGuest: false, whenDenied: 'disabled'))->toBe(Affordance::DisabledWithReason)
        ->and(Affordance::resolve(false, isGuest: false, whenDenied: 'hide'))->toBe(Affordance::Hidden)
        ->and(Affordance::resolve(false, isGuest: false))->toBe(Affordance::Hidden); // default hide
});

// ── The <x-action> component (renders each state) ─────────────────────────────────────────────────────────

it('renders the live control when the actor is allowed', function () {
    $html = Blade::render('<x-action :can="true">LIVE_CONTROL</x-action>');
    expect($html)->toContain('LIVE_CONTROL');
});

it('renders nothing when denied with the default hide intent (the ghost-UI kill)', function () {
    $this->actingAs(Users::inGroups(['members']));
    $html = Blade::render('<x-action :can="false">GHOST_CONTROL</x-action>');
    expect($html)->not->toContain('GHOST_CONTROL');
});

it('renders a disabled control carrying the reason when denied with the disabled intent', function () {
    $this->actingAs(Users::inGroups(['members']));
    $html = Blade::render('<x-action :can="false" when-denied="disabled" reason="This thread is locked." label="Reply" />');
    expect($html)->toContain('disabled')
        ->toContain('This thread is locked.')
        ->toContain('Reply');
});

it('renders a sign-in CTA to a guest when the guest intent is cta', function () {
    // No actingAs → the resolver sees a guest.
    $html = Blade::render('<x-action :can="false" when-guest="cta" cta-label="Sign in to reply" />');
    expect($html)->toContain('Sign in to reply')
        ->toContain(route('login'));
});

it('hides a denied control from a guest by default (no CTA unless asked)', function () {
    $html = Blade::render('<x-action :can="false">GUEST_GHOST</x-action>');
    expect($html)->not->toContain('GUEST_GHOST');
});
