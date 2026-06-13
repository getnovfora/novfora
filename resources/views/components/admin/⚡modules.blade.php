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
 * The ACP "Modules" page (ADR-0031 + H3 trust guardrails). Lists modules discovered on disk + installed records,
 * and drives the lifecycle (install / enable / disable / remove). Gated on admin.access (admins only — installing
 * a module is the highest-privilege act, since it loads in-process PHP with FULL server trust) PLUS staff-2FA,
 * re-asserted in mount() AND every action (a livewire/update carries no route middleware). The audited writes all
 * happen in ModuleManager; this is just the operator surface.
 *
 * H3 guardrails surfaced here: an explicit FULL-TRUST CONSENT step before a first enable (showing the module's
 * declared capabilities); an INTEGRITY badge (files match the blessed package hash, or were modified); the
 * disable-on-fatal QUARANTINE state + reason; and a global SAFE-MODE kill switch that loads no modules at all.
 */
new class extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    /** When set, the slug awaiting an explicit full-trust consent before it is enabled. */
    public ?string $pendingConsent = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function safeMode(): bool
    {
        return app(ModuleManager::class)->safeMode();
    }

    /** @return list<string> the declared capabilities (manifest `provides`) of the slug awaiting consent */
    public function consentCapabilities(): array
    {
        if ($this->pendingConsent === null) {
            return [];
        }
        try {
            return app(ModuleManager::class)->manifestFor($this->pendingConsent)->provides;
        } catch (\Throwable) {
            return [];
        }
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
                'capabilities' => $manifest->provides,
                'compatible' => ModuleApi::satisfies($manifest->apiVersion),
                'installed' => $record instanceof Module,
                'enabled' => (bool) ($record?->enabled),
                'on_disk' => true,
                'integrity' => $record instanceof Module ? $manager->integrityStatus($manifest->slug) : 'unknown',
                'quarantined' => $record?->failed_at !== null,
                'last_error' => $record?->last_error,
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
                    'capabilities' => $record->meta['provides'] ?? [],
                    'compatible' => true,
                    'installed' => true,
                    'enabled' => $record->enabled,
                    'on_disk' => false,
                    'integrity' => 'unknown', // files missing — can't recompute
                    'quarantined' => $record->failed_at !== null,
                    'last_error' => $record->last_error,
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

    /**
     * First enable of a module requires explicit full-trust consent: show the confirmation panel. A module the
     * admin has already consented to (re-enabling after a disable) enables straight away.
     */
    public function enable(string $slug): void
    {
        $this->ensureAdmin();
        $this->reset('status', 'error');
        $record = Module::where('slug', $slug)->first();
        if ($record instanceof Module && $record->consented_at !== null) {
            $this->run(fn () => app(ModuleManager::class)->enable($slug, acknowledgeTrust: true), __('Module enabled.'));

            return;
        }
        $this->pendingConsent = $slug;
    }

    public function confirmEnable(): void
    {
        $slug = $this->pendingConsent;
        if ($slug === null) {
            return;
        }
        $this->pendingConsent = null;
        $this->run(fn () => app(ModuleManager::class)->enable($slug, acknowledgeTrust: true), __('Module enabled.'));
    }

    public function cancelConsent(): void
    {
        $this->pendingConsent = null;
    }

    public function disable(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->disable($slug), __('Module disabled.'));
    }

    public function remove(string $slug): void
    {
        $this->run(fn () => app(ModuleManager::class)->remove($slug), __('Module removed.'));
    }

    public function toggleSafeMode(): void
    {
        $this->ensureAdmin();
        $this->reset('status', 'error');
        $manager = app(ModuleManager::class);
        if ($manager->safeMode()) {
            $manager->releaseSafeMode();
            $this->status = __('Safe mode turned off — modules load normally.');
        } else {
            $manager->engageSafeMode();
            $this->status = __('Safe mode is ON — no modules will load until you turn it off.');
        }
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

    @php($safe = $this->safeMode())
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-base font-semibold text-ink">{{ __('Modules & plugins') }}</h2>
                <p class="text-sm text-ink-subtle">
                    {{ __('Modules are local packages in the modules/ directory. They run in-process with full server trust — install only modules you trust. Module API version:') }}
                    <span class="nums font-medium text-ink">{{ \App\Modules\ModuleApi::VERSION }}</span>.
                </p>
            </div>
            <x-ui.button size="sm" :variant="$safe ? 'primary' : 'subtle'" wire:click="toggleSafeMode" dusk="toggle-safe-mode">
                {{ $safe ? __('Turn off safe mode') : __('Safe mode') }}
            </x-ui.button>
        </div>
        @if ($safe)
            <div class="mt-3">
                <x-ui.alert variant="warn" dusk="safe-mode-on">
                    {{ __('Safe mode is ON — no modules are loading. Use this if a module is breaking the site; turn it off once resolved.') }}
                </x-ui.alert>
            </div>
        @endif
    </x-ui.card>

    @if ($pendingConsent)
        <x-ui.card dusk="consent-panel">
            <div class="space-y-3">
                <h3 class="font-semibold text-ink">{{ __('Enable :slug?', ['slug' => $pendingConsent]) }}</h3>
                <x-ui.alert variant="warn">
                    {{ __('This module runs its own PHP in-process with FULL server trust — it can do anything your application can (read/write data, files, network). Only enable a module you obtained from a source you trust.') }}
                </x-ui.alert>
                @php($caps = $this->consentCapabilities())
                @if ($caps !== [])
                    <p class="text-sm text-ink-muted">
                        {{ __('Declared capabilities:') }}
                        <span class="text-ink">{{ implode(', ', $caps) }}</span>
                    </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="primary" wire:click="confirmEnable" dusk="confirm-enable">{{ __('I trust this module — enable') }}</x-ui.button>
                    <x-ui.button size="sm" variant="subtle" wire:click="cancelConsent" dusk="cancel-consent">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

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
                                @if ($module['quarantined'])
                                    <x-ui.badge variant="danger" dusk="quarantined">{{ __('Quarantined') }}</x-ui.badge>
                                @endif
                                @if ($module['integrity'] === 'modified')
                                    <x-ui.badge variant="warn" dusk="integrity-modified">{{ __('Files modified') }}</x-ui.badge>
                                @elseif ($module['integrity'] === 'verified')
                                    <x-ui.badge variant="success">{{ __('Integrity OK') }}</x-ui.badge>
                                @endif
                            </div>
                            @if ($module['description'])
                                <p class="mt-1 text-sm text-ink-muted">{{ $module['description'] }}</p>
                            @endif
                            @if ($module['author'])
                                <p class="mt-0.5 text-xs text-ink-subtle">{{ __('by') }} {{ $module['author'] }}</p>
                            @endif
                            @if (! empty($module['capabilities']))
                                <p class="mt-0.5 text-xs text-ink-subtle">{{ __('Capabilities:') }} {{ implode(', ', $module['capabilities']) }}</p>
                            @endif
                            @if ($module['quarantined'] && $module['last_error'])
                                <p class="mt-1 text-xs text-danger" dusk="quarantine-reason">{{ __('Disabled after an error:') }} {{ \Illuminate\Support\Str::limit($module['last_error'], 160) }}</p>
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
