<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Analytics\AnalyticsService;
use App\Forum\PostService;
use App\Models\AuditLog;
use App\Models\DailyMetric;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Users;

/*
| NOV-127 — Hearth health metrics. REAL signals only: every value is derived from actual rows (topics, posts,
| audit log, users), never estimated. These tests seed known data and assert the daily rollup computes the
| exact truthful figure.
*/

uses(RefreshDatabase::class);

function metric(string $key, string $date): ?int
{
    $v = DailyMetric::query()->where('metric_key', $key)->where('metric_date', $date)->value('value');

    return $v === null ? null : (int) $v;
}

afterEach(fn () => Carbon::setTestNow());

it('computes first-response time as the real gap to a topic first reply', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'h', 'title' => 'H', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'h-op', 'email' => 'h-op@t.test']);
    $replier = Users::inGroups(['members', 'tl2'], ['username' => 'h-rep', 'email' => 'h-rep@t.test']);

    Carbon::setTestNow('2026-07-15 12:00:00');
    $topic = app(PostService::class)->createTopic($author, $forum, 'Q', 'markdown', ['source' => 'op']);
    Carbon::setTestNow('2026-07-15 12:30:00'); // first reply 30 minutes later
    app(PostService::class)->reply($replier, $topic, 'markdown', ['source' => 'r']);

    Carbon::setTestNow('2026-07-15 23:00:00');
    app(AnalyticsService::class)->rollup(Carbon::parse('2026-07-15 12:00:00'));

    expect(metric('hearth_first_response_min', '2026-07-15'))->toBe(30);
});

it('computes unanswered-topics % from real reply counts', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'u', 'title' => 'U', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'u-op', 'email' => 'u-op@t.test']);

    Carbon::setTestNow('2026-07-15 10:00:00');
    $answered = app(PostService::class)->createTopic($author, $forum, 'Answered', 'markdown', ['source' => 'op']);
    app(PostService::class)->createTopic($author, $forum, 'Unanswered', 'markdown', ['source' => 'op']); // no reply
    app(PostService::class)->reply(Users::inGroups(['members', 'tl2'], ['username' => 'u-rep', 'email' => 'u-rep@t.test']), $answered, 'markdown', ['source' => 'r']);

    app(AnalyticsService::class)->rollup(Carbon::parse('2026-07-15 12:00:00'));

    // 2 topics created, 1 still unanswered → 50%.
    expect(metric('hearth_unanswered_pct', '2026-07-15'))->toBe(50);
});

it('counts real content-moderation actions but not assignment bookkeeping', function () {
    $this->seed();
    Carbon::setTestNow('2026-07-15 09:00:00');
    $mod = Users::inGroups(['moderators']);
    // Real content-moderation work (all counted): a lock, a delete, a spam clean, a report resolution.
    foreach (['topic.locked', 'post.deleted', 'spam.cleaned', 'report.resolved'] as $action) {
        AuditLog::create(['actor_id' => $mod->id, 'action' => $action, 'created_at' => now()]);
    }
    // NOT counted: a non-moderation action, and the self-inflatable assignment bookkeeping (NOV-127 focused review).
    AuditLog::create(['actor_id' => $mod->id, 'action' => 'user.login', 'created_at' => now()]);
    AuditLog::create(['actor_id' => $mod->id, 'action' => 'report.assigned', 'created_at' => now()]);
    AuditLog::create(['actor_id' => $mod->id, 'action' => 'report.unassigned', 'created_at' => now()]);

    app(AnalyticsService::class)->rollup(Carbon::parse('2026-07-15 12:00:00'));

    expect(metric('hearth_staff_actions', '2026-07-15'))->toBe(4);
});

it('does not fabricate a returning-members metric it cannot truthfully derive (dropped)', function () {
    $this->seed();
    Carbon::setTestNow('2026-07-15 12:00:00');
    app(AnalyticsService::class)->rollup(Carbon::parse('2026-07-15 12:00:00'));

    // The retention signal is deliberately omitted (not reconstructable from overwrite-only last_active_at).
    expect(metric('hearth_returning_active', '2026-07-15'))->toBeNull()
        ->and(AnalyticsService::METRICS)->not->toContain('hearth_returning_active');
});
