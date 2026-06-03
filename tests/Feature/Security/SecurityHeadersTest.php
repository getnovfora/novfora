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
    config(['hearth.security.headers.enabled' => false]);
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->get(route('forums.index'))->assertOk()->assertHeaderMissing('Content-Security-Policy');
});
