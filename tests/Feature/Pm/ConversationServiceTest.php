<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Messaging\ConversationService;
use App\Messaging\PmException;
use App\Models\Group;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Models\UserRelationship;
use App\Permissions\PermissionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| The PM send spine (Opus xhigh). Every anti-spam control — pm.send re-check, rate limit, mass-PM cap, the
| ignore check at BOTH start and invite — is enforced HERE at the service layer (not just the UI), and message
| bodies pass through the single ContentRenderer + ContentModerator path. report-on-PM reuses the Report poly.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function pmService(): ConversationService
{
    return app(ConversationService::class);
}

function sender(): User
{
    return Users::inGroups(['members', 'tl1']); // tl1 => pm.send ALLOW
}

it('starts a conversation, stores the opening message, and attaches participants', function () {
    $alice = sender();
    $bob = User::factory()->create();

    $convo = pmService()->startConversation($alice, [$bob->id], 'Hi Bob', 'markdown', ['source' => 'Hello there']);

    expect($convo->created_by)->toBe($alice->id)
        ->and($convo->subject)->toBe('Hi Bob')
        ->and($convo->participantRows()->count())->toBe(2)
        ->and($convo->messages()->count())->toBe(1);

    $message = $convo->messages()->first();
    expect($message->user_id)->toBe($alice->id)
        ->and($message->body_text)->toContain('Hello there');

    // The starter can invite + has read their own message; the recipient has not.
    $aliceRow = $convo->participantRows()->where('user_id', $alice->id)->first();
    $bobRow = $convo->participantRows()->where('user_id', $bob->id)->first();
    expect($aliceRow->can_invite)->toBeTrue()
        ->and($aliceRow->last_read_at)->not->toBeNull()
        ->and($bobRow->can_invite)->toBeFalse()
        ->and($bobRow->last_read_at)->toBeNull();
});

it('refuses a TL0 sender at the service layer (pm.send NEVER, defence-in-depth)', function () {
    $newbie = Users::inGroups(['members', 'tl0']);
    $bob = User::factory()->create();

    expect(fn () => pmService()->startConversation($newbie, [$bob->id], null, 'markdown', ['source' => 'hi']))
        ->toThrow(AuthorizationException::class);
});

it('sanitizes the message body through the single ContentRenderer path (no XSS)', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $payload = 'Hello <script>alert(1)</script> see [x](javascript:alert(2))';

    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => $payload]);
    $html = (string) $convo->messages()->first()->body_html_cache;

    expect($html)->not->toContain('<script>')
        ->and(strtolower($html))->not->toContain('javascript:');
});

it('silently excludes a recipient who ignores the sender', function () {
    $alice = sender();
    $blocker = User::factory()->create();
    $bob = User::factory()->create();
    UserRelationship::factory()->ignore()->create(['user_id' => $blocker->id, 'related_user_id' => $alice->id]);

    $convo = pmService()->startConversation($alice, [$blocker->id, $bob->id], null, 'markdown', ['source' => 'hi']);

    $ids = $convo->participantRows()->pluck('user_id')->all();
    expect($ids)->toContain($alice->id)
        ->and($ids)->toContain($bob->id)
        ->and($ids)->not->toContain($blocker->id);
});

it('refuses to start when every recipient ignores the sender', function () {
    $alice = sender();
    $blocker = User::factory()->create();
    UserRelationship::factory()->ignore()->create(['user_id' => $blocker->id, 'related_user_id' => $alice->id]);

    expect(fn () => pmService()->startConversation($alice, [$blocker->id], null, 'markdown', ['source' => 'hi']))
        ->toThrow(PmException::class);
});

it('enforces the mass-PM recipient cap', function () {
    config(['novfora.pm.max_recipients' => 2]);
    $alice = sender();
    $r = User::factory()->count(3)->create()->pluck('id')->all();

    expect(fn () => pmService()->startConversation($alice, $r, null, 'markdown', ['source' => 'hi']))
        ->toThrow(PmException::class);
});

it('lets a participant reply and advances last_message_at', function () {
    $alice = sender();
    $bob = Users::inGroups(['members', 'tl1']);
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'first']);
    $firstAt = $convo->fresh()->last_message_at;

    $reply = pmService()->reply($bob, $convo, 'markdown', ['source' => 'second']);

    expect($convo->messages()->count())->toBe(2)
        ->and($reply->user_id)->toBe($bob->id)
        ->and($convo->fresh()->last_message_at->gte($firstAt))->toBeTrue();
});

it('refuses a reply from a non-participant', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $outsider = sender();
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);

    expect(fn () => pmService()->reply($outsider, $convo, 'markdown', ['source' => 'sneak']))
        ->toThrow(AuthorizationException::class);
});

it('stops a participant who has been demoted to TL0 from replying', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);

    // Demote alice to TL0 (pm.send NEVER) after she started the conversation.
    $tl0 = Group::where('slug', 'tl0')->firstOrFail();
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();
    $alice->groups()->detach($tl1->id);
    $alice->groups()->attach($tl0->id, ['is_primary' => false]);
    app(PermissionResolver::class)->flushMemo();

    expect(fn () => pmService()->reply($alice->fresh(), $convo, 'markdown', ['source' => 'still here?']))
        ->toThrow(AuthorizationException::class);
});

it('adds a participant only when the inviter holds can_invite', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $carol = User::factory()->create();
    $dave = User::factory()->create();
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);

    pmService()->invite($alice, $convo, $carol->id); // alice can_invite => ok
    expect($convo->participantRows()->whereNull('left_at')->count())->toBe(3);

    // bob is a participant but lacks can_invite.
    expect(fn () => pmService()->invite($bob, $convo, $dave->id))->toThrow(AuthorizationException::class);
});

it('blocks an invite when the target ignores the inviter', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $blocker = User::factory()->create();
    UserRelationship::factory()->ignore()->create(['user_id' => $blocker->id, 'related_user_id' => $alice->id]);
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);

    expect(fn () => pmService()->invite($alice, $convo, $blocker->id))->toThrow(PmException::class);
});

it('lets a participant report a message but not an outsider', function () {
    $alice = sender();
    $bob = User::factory()->create();
    $outsider = User::factory()->create();
    $convo = pmService()->startConversation($alice, [$bob->id], null, 'markdown', ['source' => 'hi']);
    $message = $convo->messages()->first();

    $report = pmService()->report($bob, $message, 'spam');
    expect($report)->toBeInstanceOf(Report::class)
        ->and($report->reportable_type)->toBe(Message::class)
        ->and($report->reportable_id)->toBe($message->id);

    expect(fn () => pmService()->report($outsider, $message, 'nope'))->toThrow(AuthorizationException::class);
});
