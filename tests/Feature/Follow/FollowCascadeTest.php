<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionService;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Forced-cascade coverage for the follow graph (P2-M5, extending ADR-0025): deleting a user removes their
| relationship edges in BOTH directions — who they followed/ignored AND who followed/ignored them — inside
| the one cascade transaction. No orphan edge may survive at a gone user id.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('removes follow and ignore edges in both directions when the account is deleted', function () {
    $target = Users::inGroups(['members', 'tl1']);
    $fan = Users::inGroups(['members', 'tl1']);
    $idol = Users::inGroups(['members', 'tl1']);

    UserRelationship::factory()->follow()->create(['user_id' => $target->id, 'related_user_id' => $idol->id]); // target follows idol
    UserRelationship::factory()->follow()->create(['user_id' => $fan->id, 'related_user_id' => $target->id]);  // fan follows target
    UserRelationship::factory()->ignore()->create(['user_id' => $target->id, 'related_user_id' => $fan->id]);  // target ignores fan
    UserRelationship::factory()->ignore()->create(['user_id' => $idol->id, 'related_user_id' => $target->id]); // idol ignores target
    UserRelationship::factory()->follow()->create(['user_id' => $fan->id, 'related_user_id' => $idol->id]);    // bystander edge survives

    $targetId = (int) $target->id;
    $this->actingAs($target);
    app(AccountDeletionService::class)->deleteOwnAccount($target);

    expect(User::find($targetId))->toBeNull()
        ->and(UserRelationship::where('user_id', $targetId)->count())->toBe(0)
        ->and(UserRelationship::where('related_user_id', $targetId)->count())->toBe(0)
        // The unrelated edge between two surviving users is untouched.
        ->and(UserRelationship::where('user_id', $fan->id)->where('related_user_id', $idol->id)->count())->toBe(1);
});
