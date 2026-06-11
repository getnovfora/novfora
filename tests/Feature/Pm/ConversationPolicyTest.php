<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| ConversationPolicy is participant-only. PMs are co-owned PII: a non-participant must get a hard deny on
| read / reply / invite — never a data leak. A soft-left participant (left_at set) is inactive. invite() is
| additionally gated on the can_invite pivot flag. $user->can(..., $conversation) routes here (a Conversation
| arg is not a Scope, so Gate::before falls through to the auto-discovered policy).
*/

uses(RefreshDatabase::class);

it('lets an active participant view and reply', function () {
    $user = User::factory()->create();
    $convo = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);

    expect($user->can('view', $convo))->toBeTrue()
        ->and($user->can('reply', $convo))->toBeTrue();
});

it('denies a non-participant view and reply (no data leak)', function () {
    $outsider = User::factory()->create();
    $member = User::factory()->create();
    $convo = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $member->id]);

    expect($outsider->can('view', $convo))->toBeFalse()
        ->and($outsider->can('reply', $convo))->toBeFalse();
});

it('treats a soft-left participant as inactive', function () {
    $user = User::factory()->create();
    $convo = Conversation::factory()->create();
    ConversationParticipant::factory()->left()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);

    expect($user->can('view', $convo))->toBeFalse()
        ->and($user->can('reply', $convo))->toBeFalse();
});

it('gates invite on the can_invite flag and participation', function () {
    $convo = Conversation::factory()->create();
    $inviter = User::factory()->create();
    $plain = User::factory()->create();
    $outsider = User::factory()->create();
    ConversationParticipant::factory()->canInvite()->create(['conversation_id' => $convo->id, 'user_id' => $inviter->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $plain->id]);

    expect($inviter->can('invite', $convo))->toBeTrue()       // active participant + can_invite
        ->and($plain->can('invite', $convo))->toBeFalse()     // participant but no can_invite
        ->and($outsider->can('invite', $convo))->toBeFalse(); // non-participant
});
