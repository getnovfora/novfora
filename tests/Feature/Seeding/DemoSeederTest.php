<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\PollVote;
use App\Models\Reaction;
use App\Models\ReputationEvent;
use App\Models\User;
use App\Models\UserRelationship;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| Beta gate — verifies the Phase-2 demo content is seeded through the real service paths (P2-M5 Slice 4).
| Reactions, polls, PMs, follows, and badges must all land on the first seed run; a second run must be a no-op.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);  // groups / permissions / badges the demo authors need
    $this->seed(DemoSeeder::class);
});

it('seeds post reactions through the real ReactionService', function () {
    expect(Reaction::count())->toBeGreaterThan(0);
});

it('banks reputation events via the inline Reacted listeners', function () {
    expect(ReputationEvent::count())->toBeGreaterThan(0);
});

it('increments at least one user reputation_points denorm', function () {
    expect(User::where('reputation_points', '>', 0)->exists())->toBeTrue();
});

it('seeds poll votes through the real PollService', function () {
    expect(PollVote::count())->toBeGreaterThan(0);
});

it('seeds follow edges through the real FollowService', function () {
    expect(
        UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count()
    )->toBeGreaterThan(0);
});

it('seeds a private conversation through the real ConversationService', function () {
    expect(Conversation::count())->toBeGreaterThan(0);
});

it('seeds conversation messages', function () {
    expect(DB::table('messages')->count())->toBeGreaterThan(0);
});

it('awards starter badges via the badge sweep', function () {
    expect(DB::table('user_badges')->count())->toBeGreaterThan(0);
});

it('is idempotent — a second run changes nothing', function () {
    $reactions = Reaction::count();
    $repEvents = ReputationEvent::count();
    $votes = PollVote::count();
    $follows = UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count();
    $conversations = Conversation::count();
    $messages = DB::table('messages')->count();
    $badges = DB::table('user_badges')->count();

    $this->seed(DemoSeeder::class);  // second run — must be a no-op via the sentinel

    expect(Reaction::count())->toBe($reactions)
        ->and(ReputationEvent::count())->toBe($repEvents)
        ->and(PollVote::count())->toBe($votes)
        ->and(UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count())->toBe($follows)
        ->and(Conversation::count())->toBe($conversations)
        ->and(DB::table('messages')->count())->toBe($messages)
        ->and(DB::table('user_badges')->count())->toBe($badges);
});
