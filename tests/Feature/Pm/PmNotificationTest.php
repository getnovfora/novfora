<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Mail\NotificationMail;
use App\Messaging\ConversationService;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Users;

/*
| The live pm.received emitter. M2 Half-A seeded the vocabulary, renderers and prefs; SendPmNotification is the
| first live emitter. The queue is sync in tests, so MessageSent → the listener runs inline. The in-app channel
| is always written; mail follows the recipient's cadence; the sender is never notified of their own message.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
    Mail::fake();
});

it('notifies the recipient in-app when a conversation is started, but not the sender', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = User::factory()->create();

    app(ConversationService::class)->startConversation($alice, [$bob->id], 'Hi', 'markdown', ['source' => 'hello']);

    expect($bob->fresh()->notifications()->where('type', 'pm.received')->count())->toBe(1)
        ->and($alice->fresh()->notifications()->where('type', 'pm.received')->count())->toBe(0);
});

it('notifies every other active participant in a group conversation', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = User::factory()->create();
    $carol = User::factory()->create();

    app(ConversationService::class)->startConversation($alice, [$bob->id, $carol->id], null, 'markdown', ['source' => 'hello all']);

    expect($bob->fresh()->notifications()->where('type', 'pm.received')->count())->toBe(1)
        ->and($carol->fresh()->notifications()->where('type', 'pm.received')->count())->toBe(1);
});

it('merges a second message in the same conversation into one unread notification', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = User::factory()->create();

    $service = app(ConversationService::class);
    $convo = $service->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'one']);
    $service->reply($alice, $convo, 'markdown', ['source' => 'two']);

    $notes = $bob->fresh()->notifications()->where('type', 'pm.received')->get();
    expect($notes)->toHaveCount(1)
        ->and((int) $notes->first()->data['count'])->toBe(2);
});

it('keeps delivering the in-app notification with mail faked down (forced-absence, never an error)', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = User::factory()->create(); // default cadence is immediate → the Notifier attempts a mail

    app(ConversationService::class)->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);

    expect($bob->fresh()->notifications()->where('type', 'pm.received')->count())->toBe(1);
    Mail::assertQueued(NotificationMail::class); // immediate cadence attempted mail; the in-app write still landed
});
