<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Theme\Widget;

/**
 * A compact search box that posts to the existing search page (GET ?q=). Code-authored, escaped output;
 * the only setting is the placeholder text.
 */
final class SearchWidget extends Widget
{
    public function key(): string
    {
        return 'search';
    }

    public function name(): string
    {
        return 'Search box';
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'placeholder', 'label' => 'Placeholder text', 'type' => 'text', 'default' => 'Search…'],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $placeholder = is_string($settings['placeholder'] ?? null) && trim((string) $settings['placeholder']) !== ''
            ? (string) $settings['placeholder']
            : __('Search…');

        return '<form class="rounded-lg border border-line bg-surface-raised p-4" role="search" method="GET" action="'.e(route('search.index')).'">'
            .'<label class="sr-only" for="novfora-widget-q">'.e(__('Search')).'</label>'
            .'<input id="novfora-widget-q" type="search" name="q" placeholder="'.e($placeholder).'" '
            .'class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle" />'
            .'</form>';
    }
}
