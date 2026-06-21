<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Community\ActivityFeed;
use App\Models\Activity;
use App\Models\User;
use App\Theme\Widget;

/**
 * The most recent community activity (topics started, replies, reactions) — the widget form of the homepage
 * feed (BUG-020). Public, code-authored output with every dynamic value escaped (no untrusted HTML). The
 * viewer's permission filter is applied inside ActivityFeed (a guest sees only guest-visible activity, never
 * a leak), and the count is clamped (1–50) so a placement can never ask for an unbounded feed.
 */
final class RecentActivityWidget extends Widget
{
    public function key(): string
    {
        return 'recent_activity';
    }

    public function name(): string
    {
        return 'Recent activity';
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'count', 'label' => 'How many to show (1–50)', 'type' => 'number', 'default' => 20],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $count = max(1, min(50, (int) ($settings['count'] ?? 20)));

        // Resolve the viewer guest-aware (like the homepage feed) so the same per-viewer permission filter
        // inside ActivityFeed applies — the widget never widens what the viewer may see.
        $viewer = auth()->user() ?? User::guest();
        $items = app(ActivityFeed::class)->for($viewer, $count);

        if ($items === []) {
            return '';
        }

        $rows = '';
        foreach ($items as $item) {
            $phrase = match ($item->verb) {
                Activity::VERB_TOPIC_CREATED => __('started a topic'),
                Activity::VERB_POST_CREATED => __('replied in'),
                Activity::VERB_REACT_GIVEN => __('reacted to a post in'),
                default => __('posted in'),
            };

            $actor = $item->actor !== null
                ? (($item->actor->display_name ?: $item->actor->username) ?: __('[Deleted]'))
                : __('[Deleted]');

            $target = $item->isMissing()
                ? '<span class="italic text-ink-subtle">'.e(__('a removed item')).'</span>'
                : '<a class="text-accent hover:text-accent-hover" href="'.e((string) $item->url()).'">'.e((string) $item->title()).'</a>';

            $rows .= '<li class="border-b border-line py-1 text-sm last:border-0">'
                .'<span class="font-medium text-ink">'.e((string) $actor).'</span> '
                .'<span class="text-ink-subtle">'.e($phrase).'</span> '
                .$target
                .'</li>';
        }

        return '<div class="rounded-lg border border-line bg-surface-raised p-4">'
            .'<h3 class="mb-2 text-sm font-semibold text-ink">'.e(__('Recent activity')).'</h3>'
            .'<ul>'.$rows.'</ul></div>';
    }
}
