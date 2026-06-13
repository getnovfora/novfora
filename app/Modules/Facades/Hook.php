<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules\Facades;

use App\Modules\HookRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the filter-hook pipeline (ADR-0031). Core call sites use Hook::applyFilters('name', $value, …);
 * modules register via Hook::addFilter('name', $callback). Resolves the singleton HookRegistry.
 *
 * @method static void addFilter(string $name, callable $callback, int $priority = 10)
 * @method static mixed applyFilters(string $name, mixed $value, mixed ...$args)
 * @method static bool hasFilter(string $name)
 *
 * @see HookRegistry
 */
final class Hook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HookRegistry::class;
    }
}
