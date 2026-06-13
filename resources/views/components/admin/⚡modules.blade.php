<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Module;
use App\Models\User;
use App\Modules\ModuleApi;
use App\Modules\ModuleException;
use App\Modules\ModuleManager;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * The ACP "Modules" page (ADR-0031). Lists modules discovered on disk + installed records, and drives the
 * lifecycle (install / enable / disable / remove). Gated on admin.access (admins only — installing a module is
 * the highest-privilege act, since it loads in-process PHP) PLUS staff-2FA, re-asserted in mount() AND every
 * action (a livewire/update carries no route middleware). The audited writes all happen in ModuleManager;
 * this is just the operator surface, and it surfaces a ModuleException as an inline error rather than a 500.
 */
new class extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** @return list<array<string,mixed>> */
    public function modules(): array
    {
        $this->ensureAdmin();
        $manager = app(ModuleManager::class);
        $installed = Module::query()->get()->keyBy('slug');

        $rows = [];
        foreach ($manager->discover() as $manifest) {
            $record = $installed->get($manifest->slug);
            $rows[$manifest->slug] = [
                'slug' => $manifest->slug,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'api_version' => $manifest->apiVersion,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'compatible' => ModuleApi::satisfies($manifest->apiVersion),
                'installed' => $record instanceof Module,
                'enabled' => (bool) ($record?->enabled),
                'on_disk' => true,
            ];
        }
        foreach ($installed as $slug => $record) {
            if (! isset($rows[$slug])) {
                $rows[$slug] = [
                    'slug' => $slug,
                    'name' => $record->name,
                    'version' => $record->version,
                    'api_version' => $record->api_version,
                    'description' => $record->meta['description'] ?? null,
                    'author' => $record->meta['author'] ?? null,
                    'compatible' => true,
                    'installed' => true,
                    'enabled' => $record->enabled,
                    'on_disk' => false,
                ];
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    public function install(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->install($slug), __('Module installed.'));
    }

    public function enable(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->enable($slug), __('Module enabled.'));
    }

    public function disable(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->disable($slug), __('Module disabled.'));
    }

    public function remove(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->remove($slug), __('Module removed.'));
    }

    private function run(callable $action, string $success): void
    {
        $this->ensureAdmin();
        $this->reset('status', 'error');
        try {
            $action();
            $this->status = $success;
        } catch (ModuleException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-modules">
    @if ($status)
        <x-ui.alert variant="success">{{ $status }}</x-ui.alert>
    @endif
    @if ($error)
        <x-ui.alert variant="danger" dusk="module-error">{{ $error }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Modules & plugins') }}</h2>
            <p class="text-sm text-ink-subtle">
                {{ __('Modules are local packages placed in the modules/ directory. They run in-process with full trust — install only modules you trust. Module API version:') }}
                <span class="nums font-medium text-ink">{{ \App\Modules\ModuleApi::VERSION }}</span>.
            </p>
        </div>
    </x-ui.card>

    @php($rows = $this->modules())
    @if ($rows === [])
        <x-ui.card>
            <p class="text-sm text-ink-subtle" dusk="modules-empty">{{ __('No modules found. Drop a package into the modules/ directory to get started.') }}</p>
        </x-ui.card>
    @else
        <div class="space-y-3">
            @foreach ($rows as $module)
                <x-ui.card dusk="module-{{ \Illuminate\Support\Str::slug($module['slug']) }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-ink">{{ $module['name'] }}</h3>
                                <span class="text-xs text-ink-subtle">{{ $module['slug'] }}</span>
                                <span class="nums text-xs text-ink-subtle">v{{ $module['version'] }}</span>
                                @if ($module['enabled'])
                                    <x-ui.badge variant="success">{{ __('Enabled') }}</x-ui.badge>
                                @elseif ($module['installed'])
                                    <x-ui.badge variant="neutral">{{ __('Disabled') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral">{{ __('Available') }}</x-ui.badge>
                                @endif
                                @unless ($module['compatible'])
                                    <x-ui.badge variant="warn">{{ __('Incompatible') }}</x-ui.badge>
                                @endunless
                                @unless ($module['on_disk'])
                                    <x-ui.badge variant="warn">{{ __('Files missing') }}</x-ui.badge>
                                @endunless
                            </div>
                            @if ($module['description'])
                                <p class="mt-1 text-sm text-ink-muted">{{ $module['description'] }}</p>
                            @endif
                            @if ($module['author'])
                                <p class="mt-0.5 text-xs text-ink-subtle">{{ __('by') }} {{ $module['author'] }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if (! $module['installed'])
                                <x-ui.button size="sm" variant="primary" wire:click="install('{{ $module['slug'] }}')" dusk="install">{{ __('Install') }}</x-ui.button>
                            @elseif (! $module['enabled'])
                                <x-ui.button size="sm" variant="primary" wire:click="enable('{{ $module['slug'] }}')" dusk="enable">{{ __('Enable') }}</x-ui.button>
                                <x-ui.button size="sm" variant="danger" wire:click="remove('{{ $module['slug'] }}')" dusk="remove">{{ __('Remove') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="subtle" wire:click="disable('{{ $module['slug'] }}')" dusk="disable">{{ __('Disable') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
