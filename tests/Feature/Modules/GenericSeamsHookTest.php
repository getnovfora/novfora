<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Analytics\AnalyticsService;
use App\AntiSpam\ModerationVerdict;
use App\Forum\PostService;
use App\Models\DailyMetric;
use App\Models\Forum;
use App\Models\User;
use App\Modules\HookRegistry;
use App\Theme\Widgets\ForumStatsWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The API-1.2 generic module seams (ADR-0120): the `moderation.verdict` filter (ESCALATE-ONLY — a module
| may raise allow → hold → reject, never lower; the HookRegistry non-widening rule) and the
| `stats.users.query` filter (scopes which accounts count in member aggregates — counts only, never rows).
| Both are no-ops with no registered hooks, which the rest of the suite already proves wholesale.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    app(HookRegistry::class)->flush();
});

afterEach(fn () => app(HookRegistry::class)->flush());

function seamForum(): Forum
{
    return Forum::create(['slug' => 'seams', 'title' => 'Seams', 'type' => 'forum']);
}

it('lets a module filter escalate an allowed post into the moderation queue', function () {
    $author = Users::inGroups(['members', 'tl1']); // tl1: clear of the TL0 new-user hold
    app(HookRegistry::class)->addFilter('moderation.verdict', function (ModerationVerdict $verdict, User $who) use ($author): ModerationVerdict {
        if ($who->is($author)) {
            return new ModerationVerdict(ModerationVerdict::HOLD, ['module:test_hold'], $verdict->spam);
        }

        return $verdict;
    });

    $forum = seamForum();
    $topic = app(PostService::class)->createTopic($author, $forum, 'Escalated', 'tiptap_json', Content::doc('benign text'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending')
        ->and($topic->fresh()->approved_state)->toBe('pending');

    // Another author is untouched by the module's targeting.
    $other = Users::inGroups(['members', 'tl1']);
    $topic2 = app(PostService::class)->createTopic($other, $forum, 'Untouched', 'tiptap_json', Content::doc('benign text'));
    expect($topic2->posts()->firstOrFail()->approved_state)->toBe('approved');
});

it('discards a filter that tries to DE-escalate (a module can never un-hold)', function () {
    $tl0 = Users::inGroups(['members', 'tl0']); // core holds a TL0 author's first posts
    app(HookRegistry::class)->addFilter('moderation.verdict', fn (ModerationVerdict $verdict): ModerationVerdict => new ModerationVerdict(ModerationVerdict::ALLOW, [], $verdict->spam));

    $topic = app(PostService::class)->createTopic($tl0, seamForum(), 'Still held', 'tiptap_json', Content::doc('first post'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending');
});

it('ignores non-verdict and garbage-action returns from the filter', function () {
    $author = Users::inGroups(['members', 'tl1']);
    app(HookRegistry::class)->addFilter('moderation.verdict', fn (): string => 'banana');
    app(HookRegistry::class)->addFilter('moderation.verdict', fn (ModerationVerdict $v): ModerationVerdict => new ModerationVerdict('nonsense', ['module:bad'], $v->spam));

    $topic = app(PostService::class)->createTopic($author, seamForum(), 'Unaffected', 'tiptap_json', Content::doc('benign text'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('approved');
});

it('scopes member aggregates through the stats filter (widget + analytics), counts only', function () {
    $excluded = Users::inGroups(['members', 'tl1']);
    Users::inGroups(['members', 'tl1']); // a second, counted member

    $baselineWidget = fn (): int => (int) User::query()->where('status', 'active')->count();
    $before = $baselineWidget();

    app(HookRegistry::class)->addFilter(
        'stats.users.query',
        fn ($query, string $context) => $query->whereKeyNot($excluded->getKey()),
    );

    Cache::forget('novfora:widget:stats');
    $html = app(ForumStatsWidget::class)->render([]);
    expect($html)->toContain(number_format($before - 1));

    $totals = app(AnalyticsService::class)->liveTotals();
    expect($totals['users_total'])->toBe($before - 1);

    // Rollup writes the SCOPED count into the daily metric.
    app(AnalyticsService::class)->rollup(now());
    $metric = DailyMetric::query()
        ->where('metric_key', 'users_total')->where('metric_date', now()->toDateString())->firstOrFail();
    expect((int) $metric->value)->toBe($before - 1);
});

it('keeps the dashboard alive when a stats filter returns garbage', function () {
    Users::inGroups(['members', 'tl1']); // at least one countable member (the seeder creates none)
    app(HookRegistry::class)->addFilter('stats.users.query', fn (): string => 'garbage');

    Cache::forget('novfora:widget:stats');
    $count = (int) User::query()->where('status', 'active')->count();
    expect(app(ForumStatsWidget::class)->render([]))->toContain(number_format($count))
        ->and(app(AnalyticsService::class)->liveTotals()['users_total'])->toBeGreaterThan(0);
});
