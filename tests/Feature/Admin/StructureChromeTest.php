<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Page chrome for the Forums & structure admin page: the heading must not double-encode the ampersand
| (BUG-004), and the breadcrumb must name the "Forums" section, not the stale "Content" label (BUG-005).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** The visible text of the breadcrumb trail (labels only), with tags stripped. */
function breadcrumbTrail(string $html): string
{
    if (preg_match('/<nav[^>]*aria-label="Breadcrumb".*?<\/nav>/s', $html, $m) !== 1) {
        return '';
    }

    return trim(preg_replace('/\s+/', ' ', strip_tags($m[0])) ?? '');
}

it('renders the heading with a single-encoded ampersand (BUG-004)', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $html = $this->actingAs($admin)->get(route('admin.structure'))->assertOk()->getContent();

    // The shell escapes {{ $title }} once. The bug passed an already-escaped "&amp;", which Blade escaped
    // again to "&amp;amp;" (the literal the browser showed). The fix passes a bare "&".
    expect($html)->toContain('Forums &amp; structure')   // correct single encoding
        ->and($html)->not->toContain('&amp;amp;');         // no double-encoding anywhere on the page
});

it('breadcrumb names the Forums section, not "Content" (BUG-005)', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $html = $this->actingAs($admin)->get(route('admin.structure'))->assertOk()->getContent();
    $trail = breadcrumbTrail($html);

    expect($trail)->toContain('Forums')   // the section's real top-level nav label
        ->and($trail)->not->toContain('Content'); // the stale, wrong parent label is gone
});
