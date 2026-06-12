<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| Permission-mask coverage for badge.manage (P2-M5): an admin-preset key like prefix.manage — staff below
| admin and plain members never hold it by default.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

it('grants badge.manage to administrators only', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['admins']), 'badge.manage', $acl->global, true, 'group_allow');
    $acl->assertDecision($acl->user(['moderators', 'tl2']), 'badge.manage', $acl->global, false, 'default');
    $acl->assertDecision($acl->user(['members', 'tl2']), 'badge.manage', $acl->global, false, 'default');
});
