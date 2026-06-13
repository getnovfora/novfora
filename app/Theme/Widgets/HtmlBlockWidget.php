<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Widgets;

use App\Content\ContentSanitizer;
use App\Theme\Widget;

/**
 * A free-form HTML / text block an admin places into a region (announcements, links, a welcome note). The
 * admin-authored HTML is the one untrusted-input path here, so it is run through the SAME post-HTML allowlist
 * sanitiser as user content (`<script>`/`<style>`/handlers stripped) before it reaches the page — an admin can
 * never paste an XSS payload into a widget.
 */
final class HtmlBlockWidget extends Widget
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    public function key(): string
    {
        return 'html';
    }

    public function name(): string
    {
        return 'HTML / text block';
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fields(): array
    {
        return [
            ['key' => 'html', 'label' => 'Content (HTML allowed; scripts/styles are stripped)', 'type' => 'textarea', 'default' => ''],
        ];
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        $html = is_string($settings['html'] ?? null) ? $settings['html'] : '';
        if (trim($html) === '') {
            return '';
        }

        return '<div class="novfora-prose">'.$this->sanitizer->sanitize($html).'</div>';
    }
}
