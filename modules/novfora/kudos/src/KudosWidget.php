<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Modules\Novfora\Kudos;

use App\Theme\Widget;

/**
 * A module-registered layout widget (ADR-0032 dogfood) — proves a plugin can contribute a widget the admin
 * places into a layout region, exactly like a built-in. Output is code-authored + escaped.
 */
final class KudosWidget extends Widget
{
    public function key(): string
    {
        return 'kudos';
    }

    public function name(): string
    {
        return 'Kudos given';
    }

    /** @param array<string,mixed> $settings */
    public function render(array $settings): string
    {
        return '<div class="rounded-lg border border-line bg-surface-raised p-4">'
            .'<h3 class="mb-1 text-sm font-semibold text-ink">'.e(__('Kudos')).'</h3>'
            .'<p class="nums text-2xl font-bold text-ink">'.e((string) KudosServiceProvider::total()).'</p></div>';
    }
}
