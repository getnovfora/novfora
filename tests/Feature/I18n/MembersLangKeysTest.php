<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| ADR-0079 i18n guard for the members directory. The labels live in common.* (NOT a `members` group file): a
| members.php group case-collides with the live `__('Members')` string-key on a case-insensitive filesystem
| and made __('Members') return the whole array (→ 500). The string-resolves regression below locks that fix.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('resolves the members labels via common, and __(\'Members\') stays a string (collision guard)', function () {
    foreach (['members', 'directory', 'top_members'] as $key) {
        expect(trans("common.{$key}"))->not->toBe("common.{$key}");
    }

    // The collision regression: a `members` group file would make __('Members') return an array. It must be
    // the plain string (ForumStatsWidget / clubs / the nav rely on this).
    expect(trans('Members'))->toBeString()->toBe('Members');
});

it('renders the members directory in English with no raw token', function () {
    app(Settings::class)->set('members.directory_visibility', 'everyone');

    $this->get(route('members.index'))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('Directory')
        ->assertSee('Top members')
        ->assertDontSee('common.members')
        ->assertDontSee('common.directory');
});
