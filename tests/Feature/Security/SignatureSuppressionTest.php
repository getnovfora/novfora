<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The TL0 link/image suppression gate (security §2.4) must cover SIGNATURES too — a public surface
| (/users/{user}) that previously rendered a gated author's links/images unrestricted. Resolved through
| the same permission engine as post bodies. Regression for anti-spam finding F1 in SECURITY-REVIEW.md.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('suppresses links and images in a TL0 member signature', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);

    $this->actingAs($tl0)->post(route('settings.profile.save'), [
        'signature' => 'visit [my site](https://spam.example) and ![x](https://spam.example/x.png)',
    ])->assertRedirect();

    $html = (string) $tl0->fresh()->signature_html;
    expect($html)->toContain('my site')        // link text survives
        ->not->toContain('<a ')                 // …but the anchor is stripped
        ->not->toContain('spam.example')         // …and the URL (href + img src) is gone
        ->not->toContain('<img');                // the image is dropped
});

it('keeps links and images in a TL1 member signature', function () {
    $tl1 = Users::inGroups(['members', 'tl1']);

    $this->actingAs($tl1)->post(route('settings.profile.save'), [
        'signature' => 'visit [my site](https://ok.example) now',
    ])->assertRedirect();

    expect((string) $tl1->fresh()->signature_html)
        ->toContain('<a ')
        ->toContain('ok.example');
});
