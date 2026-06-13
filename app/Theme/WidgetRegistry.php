<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

/**
 * The registry of available layout widgets (ADR-0032). Built-ins are registered by ThemeServiceProvider;
 * modules may register their own via this same registry (the B1 extension stance). The layout configurator
 * lists `all()`; the renderer resolves a placement's widget by `get($key)`. A placement whose widget is no
 * longer registered (a module was disabled) simply renders nothing — never an error.
 */
final class WidgetRegistry
{
    /** @var array<string, Widget> */
    private array $widgets = [];

    public function register(Widget $widget): void
    {
        $this->widgets[$widget->key()] = $widget;
    }

    public function get(string $key): ?Widget
    {
        return $this->widgets[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$key]);
    }

    /** @return list<Widget> */
    public function all(): array
    {
        return array_values($this->widgets);
    }
}
