<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Messaging\PmAccountCascade;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The ADR-0025 PM deletion cascade. PMs are co-owned PII: a deleted participant's AUTHORED messages are
| pseudonymised (user_id NULL, body intact) so the thread stays coherent for the others; their participant
| rows hard-delete; the conversation survives while ≥1 participant remains, else it is purged; a started
| conversation keeps its thread with created_by anonymised; relationship edges hard-delete on both endpoints.
*/

uses(RefreshDatabase::class);

it('pseudonymises authored messages and keeps the thread for the remaining participant', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $convo = Conversation::factory()->create(['created_by' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $bob->id]);
    $aliceMsg = Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    $bobMsg = Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $bob->id]);

    app(PmAccountCascade::class)->purge($alice);

    expect($aliceMsg->fresh()->user_id)->toBeNull()                 // author anonymised
        ->and($aliceMsg->fresh()->body_canonical)->toBeArray()      // body retained (thread stays coherent)
        ->and($bobMsg->fresh()->user_id)->toBe($bob->id)            // the other participant's message untouched
        ->and(Conversation::whereKey($convo->id)->exists())->toBeTrue()
        ->and($convo->fresh()->created_by)->toBeNull()              // started-by attribution anonymised
        ->and(ConversationParticipant::where('conversation_id', $convo->id)->where('user_id', $alice->id)->exists())->toBeFalse()
        ->and(ConversationParticipant::where('conversation_id', $convo->id)->where('user_id', $bob->id)->exists())->toBeTrue();
});

it('purges a conversation once no participant remains', function () {
    $alice = User::factory()->create();
    $convo = Conversation::factory()->create(['created_by' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    $msg = Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);

    app(PmAccountCascade::class)->purge($alice);

    expect(Conversation::whereKey($convo->id)->exists())->toBeFalse()
        ->and(Message::whereKey($msg->id)->exists())->toBeFalse()    // cascaded with the purged conversation
        ->and(ConversationParticipant::where('conversation_id', $convo->id)->exists())->toBeFalse();
});

it('hard-deletes relationship edges on both endpoints', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    UserRelationship::factory()->ignore()->create(['user_id' => $alice->id, 'related_user_id' => $bob->id]); // alice ignores bob
    UserRelationship::factory()->ignore()->create(['user_id' => $bob->id, 'related_user_id' => $alice->id]); // bob ignores alice

    app(PmAccountCascade::class)->purge($alice);

    expect(UserRelationship::where('user_id', $alice->id)->exists())->toBeFalse()
        ->and(UserRelationship::where('related_user_id', $alice->id)->exists())->toBeFalse()
        // an edge between two OTHER users is untouched:
        ->and(UserRelationship::count())->toBe(0);
});

it('keeps a multi-party thread when only one of several participants is deleted', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $carol = User::factory()->create();
    $convo = Conversation::factory()->create(['created_by' => $bob->id]);
    foreach ([$alice, $bob, $carol] as $u) {
        ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $u->id]);
    }
    Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);

    app(PmAccountCascade::class)->purge($alice);

    expect(Conversation::whereKey($convo->id)->exists())->toBeTrue()
        ->and(ConversationParticipant::where('conversation_id', $convo->id)->whereNull('left_at')->count())->toBe(2)
        ->and($convo->fresh()->created_by)->toBe($bob->id); // bob started it, not alice → untouched
});
