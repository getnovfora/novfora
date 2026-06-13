<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

use App\Models\Module;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots ENABLED modules each request (ADR-0031): registers each module's PSR-4 namespace with a runtime
 * autoloader and registers its service provider, which is where the module wires its routes / listeners /
 * filters / slots. Only enabled, validated, ON-DISK modules are loaded — a module whose files vanished or
 * whose manifest no longer validates is silently skipped (never a fatal boot error). No code is fetched or
 * eval'd; classes load from the module's own `src/` directory.
 */
final class ModuleLoader
{
    /** @var array<string,string> namespace prefix => absolute src path */
    private array $namespaces = [];

    private bool $autoloaderRegistered = false;

    public function __construct(private readonly ModuleManager $manager) {}

    public function boot(Application $app): void
    {
        if (! $this->modulesTableReady()) {
            return; // pre-install or mid-migration: nothing to load
        }

        foreach (Module::query()->where('enabled', true)->get() as $module) {
            $this->load($app, $module);
        }
    }

    private function load(Application $app, Module $module): void
    {
        try {
            $manifest = $this->manager->manifestFor($module->slug);
        } catch (\Throwable) {
            return; // files gone / manifest broke — skip this module, keep the site up
        }

        if ($manifest->namespace !== null) {
            $this->registerNamespace($manifest->namespace, $this->manager->srcPath($module->slug));
        }
        if ($manifest->provider !== null && class_exists($manifest->provider)) {
            $app->register($manifest->provider);
        }
    }

    private function registerNamespace(string $namespace, string $src): void
    {
        if (isset($this->namespaces[$namespace])) {
            return;
        }
        $this->namespaces[$namespace] = $src;

        if ($this->autoloaderRegistered) {
            return;
        }
        $this->autoloaderRegistered = true;
        spl_autoload_register(function (string $class): void {
            foreach ($this->namespaces as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $relative = substr($class, strlen($prefix));
                    $file = $dir.'/'.str_replace('\\', '/', $relative).'.php';
                    if (is_file($file)) {
                        require $file;

                        return;
                    }
                }
            }
        });
    }

    private function modulesTableReady(): bool
    {
        try {
            return Schema::hasTable('modules');
        } catch (\Throwable) {
            return false;
        }
    }
}
