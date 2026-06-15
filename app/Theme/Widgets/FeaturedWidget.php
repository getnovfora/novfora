<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Content\ContentSanitizer;
use App\Theme\Widget;

/**
 * A highlighted "featured" panel — an admin-authored title + HTML body, styled with the accent surface. The
 * body is the one untrusted-input path, so it goes through the SAME post-HTML allowlist as user content
 * (scripts/styles/handlers stripped) before it reaches the page.
 */
final class FeaturedWidget extends Widget
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function key(): string
    {
        return 'featured';
    }

    public function name(): string
    {
        return 'Featured panel';
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Panel title', 'type' => 'text', 'default' => 'Featured'],
            ['key' => 'body', 'label' => 'Body (HTML allowed; scripts/styles are stripped)', 'type' => 'textarea', 'default' => ''],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $body = is_string($settings['body'] ?? null) ? $settings['body'] : '';
        if (trim($body) === '') {
            return '';
        }

        $title = is_string($settings['title'] ?? null) && trim((string) $settings['title']) !== ''
            ? (string) $settings['title']
            : __('Featured');

        return '<div class="rounded-lg border border-accent/30 bg-accent-soft p-4">'
            .'<h3 class="mb-2 text-sm font-semibold text-accent-soft-ink">'.e($title).'</h3>'
            .'<div class="novfora-prose text-accent-soft-ink">'.$this->sanitizer->sanitize($body).'</div></div>';
    }
}
