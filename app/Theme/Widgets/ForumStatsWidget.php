<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Modules\Facades\Hook;
use App\Theme\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * A small board-statistics card (members / topics / posts). The figures are public aggregates, cached for a
 * minute so the widget never adds three COUNTs to every forum-index render. All output is code-authored with
 * escaped values — no untrusted HTML.
 *
 * MODULE SEAM (API 1.2): the member count runs through the `stats.users.query` filter (value: the User
 * query builder; arg: a context label) so a module can scope which accounts count as members (e.g. a
 * seeding plugin excluding its simulated accounts). Public aggregate only — the 60s cache means a filter
 * change can lag by up to a minute, which is acceptable staleness for a count.
 */
final class ForumStatsWidget extends Widget
{
    public function key(): string
    {
        return 'stats';
    }

    public function name(): string
    {
        return 'Board statistics';
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        /** @var array{members:int,topics:int,posts:int} $counts */
        $counts = Cache::remember('novfora:widget:stats', now()->addMinute(), function (): array {
            $members = Hook::applyFilters('stats.users.query', User::query()->where('status', 'active'), 'widget.members');

            return [
                'members' => (int) ($members instanceof Builder ? $members : User::query()->where('status', 'active'))->count(),
                'topics' => (int) Topic::query()->count(),
                'posts' => (int) Post::query()->count(),
            ];
        });

        $rows = [
            __('Members') => $counts['members'],
            __('Topics') => $counts['topics'],
            __('Posts') => $counts['posts'],
        ];
        $items = '';
        foreach ($rows as $label => $value) {
            $items .= '<div class="flex items-baseline justify-between gap-3 border-b border-line py-1 text-sm">'
                .'<dt class="text-ink-subtle">'.e($label).'</dt>'
                .'<dd class="nums font-semibold text-ink">'.e(number_format($value)).'</dd>'
                .'</div>';
        }

        return '<div class="rounded-lg border border-line bg-surface-raised p-4">'
            .'<h3 class="mb-2 text-sm font-semibold text-ink">'.e(__('Board statistics')).'</h3>'
            .'<dl>'.$items.'</dl></div>';
    }
}
