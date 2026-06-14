<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Models\Topic;
use App\Theme\Widget;

/**
 * A list of the most recently active topics. Public, code-authored output with every dynamic value escaped —
 * no untrusted HTML. The count is clamped (1–20) so a placement can never ask for an unbounded query.
 */
final class RecentTopicsWidget extends Widget
{
    public function key(): string
    {
        return 'recent_topics';
    }

    public function name(): string
    {
        return 'Recent topics';
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'count', 'label' => 'How many to show (1–20)', 'type' => 'number', 'default' => 5],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $count = max(1, min(20, (int) ($settings['count'] ?? 5)));

        // SoftDeletes excludes trashed automatically. Most-recently-active first.
        $topics = Topic::query()
            ->orderByDesc('last_posted_at')->orderByDesc('id')
            ->limit($count)->get(['id', 'title', 'last_posted_at']);

        if ($topics->isEmpty()) {
            return '';
        }

        $items = '';
        foreach ($topics as $topic) {
            $items .= '<li class="border-b border-line py-1 text-sm last:border-0">'
                .'<a class="text-ink hover:text-accent" href="'.e(route('topics.show', $topic)).'">'.e((string) $topic->title).'</a>'
                .'</li>';
        }

        return '<div class="rounded-lg border border-line bg-surface-raised p-4">'
            .'<h3 class="mb-2 text-sm font-semibold text-ink">'.e(__('Recent topics')).'</h3>'
            .'<ul>'.$items.'</ul></div>';
    }
}
