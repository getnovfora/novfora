<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

/**
 * The synchronous FILTER-HOOK pipeline (ADR-0031 §seams). A core extension point calls
 * `applyFilters($name, $value, ...$args)`; every callback a module registered for `$name` (in priority order,
 * low → high) receives the running value and returns a possibly-transformed value. With no registered hooks
 * the value passes through unchanged — so a core call site is a no-op until a module opts in.
 *
 * This is a value-transform contract, NOT an authority: a filter can shape data a core call site chooses to
 * expose, but it can never widen a permission decision (those resolve only through PermissionResolver) and any
 * HTML a filter produces is still sanitised by the core call site that consumes it.
 */
final class HookRegistry
{
    /** @var array<string, list<array{priority:int, seq:int, callback:callable}>> */
    private array $filters = [];

    private int $seq = 0;

    public function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        $this->filters[$name][] = ['priority' => $priority, 'seq' => $this->seq++, 'callback' => $callback];
    }

    public function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        $hooks = $this->filters[$name] ?? [];
        if ($hooks === []) {
            return $value;
        }
        // Stable order: priority asc, then registration order (seq) so equal priorities are deterministic.
        usort($hooks, fn (array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);

        foreach ($hooks as $hook) {
            $value = ($hook['callback'])($value, ...$args);
        }

        return $value;
    }

    public function hasFilter(string $name): bool
    {
        return ! empty($this->filters[$name]);
    }

    public function flush(): void
    {
        $this->filters = [];
        $this->seq = 0;
    }
}
