<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| BETA-2 / NOV-86 — mobile portrait nav spillover regression guard. The header's responsive contract:
| exactly ONE flex child (the brand link, min-w-0) yields when the row is over-full; the right cluster
| stays shrink-0; nav/search/sign-up appear only at their breakpoints; nav item titles never wrap to a
| second text line inside the h-14 bar. These are markup assertions — the geometric proof at 390px lives
| in the CI-only Dusk spec (tests/Browser/MobileHeaderJourneyTest.php).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('keeps the responsive header contract at mobile widths', function () {
    $html = $this->get(route('forums.index'))->assertOk()->getContent();

    expect($html)
        ->toContain('sm:hidden flex items-center')            // hamburger wrapper owns nav below sm
        ->toContain('hidden sm:flex items-center gap-0.5 md:gap-1') // primary nav hidden below sm
        ->toContain('hidden md:flex ml-auto')                 // desktop search hidden below md
        ->toContain('hidden sm:contents');                    // guest Sign-up hidden below sm
});

it('lets the brand link shrink so the signed-in bar cannot spill', function () {
    $member = Users::inGroups(['members']);
    $html = $this->actingAs($member)->get(route('forums.index'))->assertOk()->getContent();

    preg_match('/<a href="[^"]*" class="([^"]*)">\s*(?:<!--[\s\S]*?-->\s*)?<span class="([^"]*)">/', $html, $m);
    $brand = collect(explode("\n", $html))->first(fn ($line) => str_contains($line, 'href="'.route('forums.index').'"') && str_contains($line, 'tracking-tight'));

    expect($brand)->not->toBeNull()
        ->and($brand)->toContain('min-w-0')
        ->and($brand)->toContain('whitespace-nowrap')
        ->and($brand)->not->toContain('shrink-0');

    // The text wordmark keeps its truncate guard (ellipsis instead of wrap/overflow).
    expect($html)->toContain('max-w-[55vw] truncate');
});

it('keeps primary nav item titles on one line', function () {
    $member = Users::inGroups(['members']);
    $html = $this->actingAs($member)->get(route('forums.index'))->assertOk()->getContent();

    // Every rendered primary-nav item link carries whitespace-nowrap (NavigationManager items are
    // admin-editable, so multi-word titles must degrade to truncation, never a two-line bar).
    $navStart = strpos($html, 'aria-label="Primary"');
    $navEnd = strpos($html, '</nav>', $navStart);
    $nav = substr($html, $navStart, $navEnd - $navStart);

    preg_match_all('/class="flex items-center gap-1\.5 min-h-11 px-3[^"]*"/', $nav, $links);
    expect($links[0])->not->toBeEmpty();
    foreach ($links[0] as $classAttr) {
        expect($classAttr)->toContain('whitespace-nowrap');
    }
});
