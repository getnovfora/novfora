<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionService;
use App\Community\BadgeService;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| Forced-cascade coverage for badges (P2-M5, extending ADR-0025): deleting a user removes their
| user_badges rows inside the one cascade transaction; the badge definitions and other users' awards
| are untouched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('removes the deleted user badge awards and nothing else', function () {
    $target = Users::inGroups(['members', 'tl1']);
    $bystander = Users::inGroups(['members', 'tl1']);
    $badge = Badge::where('slug', 'welcome')->firstOrFail();

    $service = app(BadgeService::class);
    $service->award($target, $badge);
    $service->award($bystander, $badge);

    $targetId = (int) $target->id;
    $this->actingAs($target);
    app(AccountDeletionService::class)->deleteOwnAccount($target);

    expect(User::find($targetId))->toBeNull()
        ->and(DB::table('user_badges')->where('user_id', $targetId)->count())->toBe(0)
        ->and(DB::table('user_badges')->where('user_id', $bystander->id)->count())->toBe(1)
        ->and(Badge::where('slug', 'welcome')->exists())->toBeTrue();
});
