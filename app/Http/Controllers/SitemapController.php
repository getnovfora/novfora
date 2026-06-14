<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * XML sitemap (system-architecture §6). Lists publicly-viewable, non-empty forums and approved topics;
 * empty containers and forums a guest can't see are excluded (protecting crawl budget and not leaking
 * private content). Cached (cron-refreshable) — never load-bearing for correctness.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $xml = Cache::remember('novfora.sitemap', now()->addHour(), fn () => $this->build());

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    private function build(): string
    {
        $guest = User::guest();

        // Only forums a guest can view AND that actually contain topics (skip empty containers/categories).
        $forums = Forum::query()->where('type', 'forum')->where('topic_count', '>', 0)->get()
            ->filter(fn (Forum $f) => $guest->canDo('forum.view', $f->permissionScope()));
        $forumIds = $forums->pluck('id')->all();

        // Static discovery landing pages (depth, discovery 3.4).
        $urls = [
            ['loc' => route('forums.index')],
            ['loc' => route('trending.index')],
            ['loc' => route('tags.index')],
        ];
        foreach ($forums as $forum) {
            $urls[] = ['loc' => route('forums.show', $forum)];
        }

        // Tag pages (only tags actually in use) — public listings, deepen crawl coverage.
        Tag::query()->where('usage_count', '>', 0)->orderByDesc('usage_count')->limit(5000)
            ->pluck('slug')
            ->each(function (string $slug) use (&$urls) {
                $urls[] = ['loc' => route('tags.show', $slug)];
            });

        if ($forumIds !== []) {
            Topic::query()->whereIn('forum_id', $forumIds)
                ->where('approved_state', 'approved')->whereNotNull('last_posted_at')
                ->orderByDesc('last_posted_at')->limit(50000)
                ->get(['id', 'slug', 'last_posted_at'])
                ->each(function (Topic $topic) use (&$urls) {
                    $urls[] = ['loc' => route('topics.show', $topic), 'lastmod' => $topic->last_posted_at?->toAtomString()];
                });
        }

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $out .= '  <url><loc>'.e($url['loc']).'</loc>';
            if (! empty($url['lastmod'])) {
                $out .= '<lastmod>'.e($url['lastmod']).'</lastmod>';
            }
            $out .= '</url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }
}
