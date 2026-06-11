<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Messaging\ConversationService;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/** Count the DB queries one request issues (local to this file so it runs in isolation). */
function pmQueryCount(Closure $request): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $request();

    return $count;
}

/*
| The PM pages render end-to-end (the SFCs are not type-checked by Larastan, so this is the runtime guard),
| route auth holds (participant-only show, pm.send-gated composer, guest redirect), and the query budgets are
| met: inbox ≤15, conversation ≤30. Rate limiting is disabled here (set to 0) so seeding many sends doesn't
| trip it — the cap itself is covered by PmRateLimiterTest / ConversationServiceTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
    config(['novfora.pm.rate_limits' => ['tl1' => 0, 'tl2' => 0, 'default' => 0]]); // disable the limiter for bulk seeding
});

it('renders the inbox for its owner within the query budget (≤15, no N+1)', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $service = app(ConversationService::class);
    foreach (range(1, 6) as $n) {
        $other = User::factory()->create();
        $service->startConversation($user, [$other->id], "Subject {$n}", 'markdown', ['source' => "Hi {$n}"]);
    }

    $this->actingAs($user)->get(route('pm.inbox'))->assertOk()->assertSee('Subject 6');
    $queries = pmQueryCount(fn () => $this->actingAs($user)->get(route('pm.inbox'))->assertOk());

    expect($queries)->toBeLessThanOrEqual(15);
});

it('renders a conversation for a participant within the query budget (≤30, no N+1)', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $others = User::factory()->count(3)->create();
    $service = app(ConversationService::class);
    $convo = $service->startConversation($alice, $others->pluck('id')->map(fn ($id) => (int) $id)->all(), 'Group chat', 'markdown', ['source' => 'Opening']);
    foreach (range(1, 8) as $i) {
        $service->reply($alice, $convo, 'markdown', ['source' => "message {$i}"]);
    }

    $this->actingAs($alice)->get(route('pm.show', $convo))->assertOk()->assertSee('Group chat');
    $queries = pmQueryCount(fn () => $this->actingAs($alice)->get(route('pm.show', $convo))->assertOk());

    expect($queries)->toBeLessThanOrEqual(30);
});

it('403s a non-participant on a conversation (no data leak)', function () {
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = User::factory()->create();
    $outsider = Users::inGroups(['members', 'tl1']);
    $convo = app(ConversationService::class)->startConversation($alice, [$bob->id], 'Secret', 'markdown', ['source' => 'shh']);

    $this->actingAs($outsider)->get(route('pm.show', $convo))->assertForbidden();
});

it('opens the composer for a TL1 user but 403s a TL0 user', function () {
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('pm.create'))->assertOk();
    $this->actingAs(Users::inGroups(['members', 'tl0']))->get(route('pm.create'))->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(route('pm.inbox'))->assertRedirect(route('login'));
});
