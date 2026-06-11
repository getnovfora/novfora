<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Baseline security response headers (security §4). Every web response carries a CSP plus the standard
| hardening headers. The default CSP is intentionally non-breaking (keeps script/style permissive for
| Livewire + Alpine) but still removes object/base/frame/form sinks. Regression for SECURITY-REVIEW.md.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('sends the hardening headers on a normal page', function () {
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->get(route('forums.index'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('sends a CSP that locks down the high-value sinks', function () {
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $csp = $this->get(route('forums.index'))->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("form-action 'self'");
});

it('can be disabled by config (operator escape hatch)', function () {
    config(['novfora.security.headers.enabled' => false]);
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->get(route('forums.index'))->assertOk()->assertHeaderMissing('Content-Security-Policy');
});

it('emits a strict, nonce-based CSP when opted in (F-M3)', function () {
    config(['novfora.security.csp.strict' => true]);
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $csp = (string) $this->get(route('forums.index'))->assertOk()->headers->get('Content-Security-Policy');

    // script-src is nonce-based (no 'unsafe-inline' → inline-script injection blocked); the high-value
    // sinks stay locked down. 'unsafe-eval' is retained (Alpine) — see SECURITY-REVIEW.md F-M3.
    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->not->toContain("'unsafe-inline' 'unsafe-eval'") // the baseline's permissive script-src is gone
        ->toContain("object-src 'none'");
});
