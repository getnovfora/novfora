<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Theme\LayoutManager;
use App\Theme\ThemeApi;
use App\Theme\WidgetRegistry;
use App\Theme\Widgets\FeaturedWidget;
use App\Theme\Widgets\OnlineUsersWidget;
use App\Theme\Widgets\RecentTopicsWidget;
use App\Theme\Widgets\SearchWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Theme Studio 1.3 — the layout configurator reaches more pages (board / topic / profile / sidebar / site
| header & footer) and ships a fuller first-party widget set (recent topics, who's online, search, featured).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

it('expands the region set and bumps the theme-API minor version', function () {
    expect(ThemeApi::VERSION)->toBe('1.2.0');

    $regions = app(LayoutManager::class)->regions();
    foreach (['board_top', 'board_bottom', 'topic_top', 'topic_bottom', 'profile_top', 'forum_sidebar', 'site_header', 'site_footer'] as $r) {
        expect($regions)->toHaveKey($r);
        expect(app(LayoutManager::class)->isRegion($r))->toBeTrue();
    }
});

it('registers the fuller first-party widget set', function () {
    $registry = app(WidgetRegistry::class);
    foreach (['recent_topics', 'online_users', 'search', 'featured', 'html', 'stats'] as $key) {
        expect($registry->has($key))->toBeTrue();
    }
});

it('renders recent topics with escaped titles and links', function () {
    $f = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $u = User::factory()->create();
    Topic::create(['slug' => 'hello', 'title' => 'Hello <b>World</b>', 'forum_id' => $f->id, 'user_id' => $u->id, 'last_posted_at' => now()]);

    $html = app(RecentTopicsWidget::class)->render(['count' => 5]);

    expect($html)->toContain('Hello &lt;b&gt;World&lt;/b&gt;')  // escaped, not raw markup
        ->and($html)->toContain('/topics/');
});

it('shows only members active within the window in the online widget', function () {
    User::factory()->create(['username' => 'onlinejane', 'status' => 'active', 'last_active_at' => now()]);
    User::factory()->create(['username' => 'awaybob', 'status' => 'active', 'last_active_at' => now()->subHours(2)]);

    $html = app(OnlineUsersWidget::class)->render(['minutes' => 15]);

    expect($html)->toContain('onlinejane')
        ->and($html)->not->toContain('awaybob');
});

it('renders a search form to the search page', function () {
    $html = app(SearchWidget::class)->render(['placeholder' => 'Find stuff']);

    expect($html)->toContain(route('search.index'))
        ->and($html)->toContain('Find stuff')
        ->and($html)->toContain('name="q"');
});

it('sanitises the featured panel body and hides an empty one', function () {
    $html = app(FeaturedWidget::class)->render(['title' => 'Hot', 'body' => '<p>Read this</p><script>x()</script>']);
    expect($html)->toContain('Hot')->toContain('Read this')->not->toContain('<script');

    expect(app(FeaturedWidget::class)->render(['title' => 'Hot', 'body' => '   ']))->toBe('');
});

it('renders a configured widget in the new board region on the page', function () {
    $this->seed();
    $f = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $placement = app(LayoutManager::class)->add('board_top', 'html');
    app(LayoutManager::class)->updateSettings($placement, ['html' => '<p>Board notice here</p>']);

    $this->get(route('forums.show', $f))->assertOk()->assertSee('Board notice here');
});
