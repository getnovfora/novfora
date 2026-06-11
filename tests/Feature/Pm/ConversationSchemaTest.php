<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hydrates participants, ordered messages, and the creator', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $convo = Conversation::factory()->create(['created_by' => $alice->id]);

    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $bob->id]);

    Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $alice->id]);
    Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $bob->id]);

    expect($convo->participants()->count())->toBe(2)
        ->and($convo->messages()->count())->toBe(2)
        ->and($convo->creator->is($alice))->toBeTrue()
        ->and($alice->conversations()->count())->toBe(1);
});

it('enforces one participant row per (conversation, user)', function () {
    $convo = Conversation::factory()->create();
    $user = User::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);

    expect(fn () => ConversationParticipant::factory()->create([
        'conversation_id' => $convo->id, 'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

it('enforces one relationship edge per (actor, target, type) but allows distinct types', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    UserRelationship::factory()->ignore()->create(['user_id' => $alice->id, 'related_user_id' => $bob->id]);
    // A different type for the same pair is a distinct edge — allowed.
    UserRelationship::factory()->follow()->create(['user_id' => $alice->id, 'related_user_id' => $bob->id]);

    expect(fn () => UserRelationship::factory()->ignore()->create([
        'user_id' => $alice->id, 'related_user_id' => $bob->id,
    ]))->toThrow(QueryException::class);
});

it('cascades messages and participant rows when a conversation is purged', function () {
    $convo = Conversation::factory()->create();
    $user = User::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);
    Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $user->id]);

    $convo->delete();

    expect(Message::where('conversation_id', $convo->id)->count())->toBe(0)
        ->and(ConversationParticipant::where('conversation_id', $convo->id)->count())->toBe(0);
});

it('treats a message author as nullable so a pseudonymised message resolves to null', function () {
    $message = Message::factory()->create();
    $message->update(['user_id' => null]);

    expect($message->fresh()->author)->toBeNull()
        ->and($message->fresh()->body_canonical)->toBeArray(); // body retained (ADR-0025)
});
