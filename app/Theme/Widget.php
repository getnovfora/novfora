<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

/**
 * A layout WIDGET (ADR-0032) — a unit of content an admin can place into a layout region via the configurator.
 * Built-in widgets ship with the core; modules may register their own through WidgetRegistry (the same
 * extension stance as the module slot system). The contract is small and stable:
 *
 *   - key()    : a stable identifier stored in placements (never change it across a major)
 *   - name()   : a human label for the configurator
 *   - fields() : the settings the configurator should collect (a closed set of input descriptors)
 *   - render() : the widget's HTML for a given settings array
 *
 * SECURITY: render() must return SAFE html. Built-in widgets escape every dynamic value (e()) and the
 * admin-authored HTML-block widget runs its input through the post-HTML sanitiser. A widget that needs raw
 * untrusted HTML must sanitise it itself — the layout never blanket-trusts widget output it didn't author.
 */
abstract class Widget
{
    abstract public function key(): string;

    abstract public function name(): string;

    /** @param array<string,mixed> $settings */
    abstract public function render(array $settings): string;

    /**
     * The settings inputs the configurator should render. Each: key, label, a type the configurator knows
     * (`text` | `textarea` | `number`), and an optional default.
     *
     * @return list<array{key:string,label:string,type:string,default?:mixed}>
     */
    public function fields(): array
    {
        return [];
    }
}
