<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

use App\Content\ContentSanitizer;

/**
 * The UI-SLOT registry (ADR-0031 §seams). A core template exposes a named outlet via
 * `<x-slot-outlet name="topic.sidebar" />`; modules register renderers for that name, each returning an HTML
 * string (typically a rendered Blade view). The outlet concatenates them in priority order.
 *
 * SECURITY (apex): every byte a module contributes to a slot is run through the SAME server-side allowlist
 * sanitiser as user post HTML (App\Content\ContentSanitizer) before it reaches the page. So a module — even
 * though it runs in-process with full PHP trust — can never smuggle `<script>`/`<style>` or otherwise
 * unsanitised markup into a rendered page through a slot. Interactive UI is out of scope for a slot string;
 * a module that needs it registers its own route / Livewire component instead.
 */
final class SlotRegistry
{
    /** @var array<string, list<array{priority:int, seq:int, renderer:callable}>> */
    private array $slots = [];

    private int $seq = 0;

    public function addSlot(string $name, callable $renderer, int $priority = 10): void
    {
        $this->slots[$name][] = ['priority' => $priority, 'seq' => $this->seq++, 'renderer' => $renderer];
    }

    /**
     * Render every renderer registered for $name, sanitised and concatenated. Returns '' when the slot is
     * empty (so the outlet emits nothing).
     *
     * @param  array<string,mixed>  $context
     */
    public function render(string $name, array $context = []): string
    {
        $renderers = $this->slots[$name] ?? [];
        if ($renderers === []) {
            return '';
        }
        usort($renderers, fn (array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);

        $html = '';
        foreach ($renderers as $slot) {
            $out = ($slot['renderer'])($context);
            if (is_string($out)) {
                $html .= $out;
            }
        }
        if (trim($html) === '') {
            return '';
        }

        return app(ContentSanitizer::class)->sanitize($html);
    }

    public function has(string $name): bool
    {
        return ! empty($this->slots[$name]);
    }

    public function flush(): void
    {
        $this->slots = [];
        $this->seq = 0;
    }
}
