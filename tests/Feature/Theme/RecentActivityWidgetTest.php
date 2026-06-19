<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Theme\WidgetRegistry;
use App\Theme\Widgets\RecentActivityWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| BUG-020: a first-party "Recent activity" widget so the homepage activity feed is widget-controlled like the
| other built-ins. It mirrors RecentTopicsWidget, clamps its count (1–50), and renders the permission-filtered
| feed for the current viewer.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

it('registers the Recent activity widget with a clamped count field (BUG-020)', function () {
    $registry = app(WidgetRegistry::class);

    expect($registry->has('recent_activity'))->toBeTrue();

    $widget = $registry->get('recent_activity');
    expect($widget)->toBeInstanceOf(RecentActivityWidget::class)
        ->and($widget->name())->toBe('Recent activity');

    $count = collect($widget->fields())->firstWhere('key', 'count');
    expect($count)->not->toBeNull()
        ->and($count['type'])->toBe('number')
        ->and($count['default'])->toBe(20);
});

it('renders at most the clamped count of activity items (BUG-020)', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $posts = app(PostService::class);
    foreach (range(1, 3) as $i) {
        $posts->createTopic($author, $forum, "Topic {$i}", 'tiptap_json', Content::doc("body {$i}"));
    }

    $widget = app(WidgetRegistry::class)->get('recent_activity');
    $viewer = Users::inGroups(['members', 'tl1']);
    $this->actingAs($viewer);

    $html = $widget->render(['count' => 2]);

    expect($html)->toContain('Recent activity')
        ->and(substr_count($html, '<li'))->toBe(2);
});
