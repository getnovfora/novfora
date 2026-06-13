<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Modules\Test\Faulty;

use Illuminate\Support\ServiceProvider;

/**
 * A deliberately broken module provider (test fixture). It throws while registering, so the ModuleLoader's
 * disable-on-fatal guardrail (ADR-0031 H3) must catch it and QUARANTINE the module rather than letting it
 * white-screen the site.
 */
final class FaultyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new \RuntimeException('boom: this module crashes on load');
    }
}
