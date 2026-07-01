<?php

// SPDX-License-Identifier: Apache-2.0

use App\Embeds\EmbedManager;
use App\Models\EmbedSite;
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The ACP "Embeds" page (U7, ADR-0103). Admins register the external origins allowed to frame /embed/v1
 * widgets or load the <novfora-*> web components, toggle/rotate/revoke their public site keys, and flip the
 * feature master switch. Admins-only (admin.access + staff-2FA), re-asserted in mount() AND every action
 * (a livewire/update carries no route middleware); all writes go through the audited EmbedManager, and the
 * master switch writes through Settings (audited as settings.updated).
 */
new class extends Component
{
    public string $name = '';

    public string $origin = '';

    /** @var array<int,string> */
    public array $widgets = [];

    public ?string $error = null;

    public ?string $status = null;

    /** A just-created/rotated key, shown ONCE in full so the admin can copy it. */
    public ?string $freshKey = null;

    public ?int $freshKeySiteId = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function toggleFeature(): void
    {
        $this->ensureAdmin();
        $settings = app(Settings::class);
        $settings->set('embeds.enabled', ! $settings->bool('embeds.enabled'));
        $this->reset('error', 'status');
    }

    public function create(): void
    {
        $this->ensureAdmin();
        $this->reset('error', 'status', 'freshKey', 'freshKeySiteId');

        try {
            $site = app(EmbedManager::class)->create($this->name, $this->origin, $this->widgets ?: null);
            $this->reset('name', 'origin', 'widgets');
            $this->status = __('Embed site registered.');
            $this->freshKey = $site->key;
            $this->freshKeySiteId = (int) $site->id;
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggle(int $id): void
    {
        $this->ensureAdmin();
        $site = EmbedSite::findOrFail($id);
        app(EmbedManager::class)->update($site, ['is_enabled' => ! $site->is_enabled]);
    }

    public function rotate(int $id): void
    {
        $this->ensureAdmin();
        $this->reset('error', 'status', 'freshKey', 'freshKeySiteId');
        $site = EmbedSite::findOrFail($id);
        $this->freshKey = app(EmbedManager::class)->rotate($site);
        $this->freshKeySiteId = $id;
        $this->status = __('Key rotated — the previous key stopped working immediately.');
    }

    public function remove(int $id): void
    {
        $this->ensureAdmin();
        $this->reset('freshKey', 'freshKeySiteId');
        app(EmbedManager::class)->delete(EmbedSite::findOrFail($id));
        $this->status = __('Embed site removed.');
    }

    public function featureEnabled(): bool
    {
        return app(Settings::class)->bool('embeds.enabled');
    }

    /** @return Collection<int,EmbedSite> */
    public function sites(): Collection
    {
        $this->ensureAdmin();

        return EmbedSite::query()->latest()->get();
    }

    /** @return list<string> */
    public function availableWidgets(): array
    {
        return EmbedManager::WIDGETS;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-embeds">
    @if ($status) <x-ui.alert variant="success">{{ $status }}</x-ui.alert> @endif
    @if ($error) <x-ui.alert variant="danger" dusk="embed-error">{{ $error }}</x-ui.alert> @endif

    <x-ui.card class="flex items-center justify-between gap-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Embed widgets') }}</h2>
            <p class="text-sm text-ink-subtle">
                {{ __('Let registered external sites show guest-visible widgets (latest topics, board stats) via an iframe or the <novfora-*> web components. Content is always fenced to what a signed-out visitor can see.') }}
            </p>
        </div>
        <x-ui.button type="button" wire:click="toggleFeature" :variant="$this->featureEnabled() ? 'secondary' : 'primary'" dusk="embed-feature-toggle">
            {{ $this->featureEnabled() ? __('Disable') : __('Enable') }}
        </x-ui.button>
    </x-ui.card>

    @unless ($this->featureEnabled())
        <x-ui.alert variant="warn">{{ __('The embed endpoints are OFF — every widget URL returns 404 until you enable the feature.') }}</x-ui.alert>
    @endunless

    <x-ui.card class="space-y-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Register an embedding site') }}</h2>
            <p class="text-sm text-ink-subtle">
                {{ __('The origin is granted frame-ancestors + CORS for its key — exact scheme://host, no path. The site key is public (it ships in that page\'s HTML); rotate it any time.') }}
            </p>
        </div>
        <form wire:submit="create" class="space-y-3">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" placeholder="Marketing site" dusk="embed-name" />
            <x-ui.input name="origin" :label="__('Origin (scheme://host)')" wire:model="origin" placeholder="https://example.com" dusk="embed-origin" />
            <fieldset class="space-y-1.5">
                <legend class="text-sm font-medium text-ink">{{ __('Allowed widgets (none checked = all)') }}</legend>
                @foreach ($this->availableWidgets() as $widget)
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" value="{{ $widget }}" wire:model="widgets" class="rounded border-line">
                        <code class="text-xs">{{ $widget }}</code>
                    </label>
                @endforeach
            </fieldset>
            <x-ui.button type="submit" variant="primary" dusk="embed-create">{{ __('Register site') }}</x-ui.button>
        </form>
    </x-ui.card>

    @php($sites = $this->sites())
    @if ($sites->isNotEmpty())
        <div class="space-y-3">
            @foreach ($sites as $site)
                <x-ui.card class="space-y-3" dusk="embed-site-{{ $site->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-ink">{{ $site->name }}</span>
                                <x-ui.badge :variant="$site->is_enabled ? 'success' : 'neutral'">
                                    {{ $site->is_enabled ? __('Enabled') : __('Disabled') }}
                                </x-ui.badge>
                            </div>
                            <div class="text-sm text-ink-muted">{{ $site->origin }}</div>
                            <div class="text-xs text-ink-subtle">
                                {{ __('Widgets:') }} {{ $site->widgets === null ? __('all') : implode(', ', $site->widgets) }}
                                · {{ __('Key ends in') }} <code>…{{ substr($site->key, -6) }}</code>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="toggle({{ $site->id }})" dusk="embed-toggle-{{ $site->id }}">
                                {{ $site->is_enabled ? __('Disable') : __('Enable') }}
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="rotate({{ $site->id }})"
                                         wire:confirm="{{ __('Rotate the key? Embeds using the current key stop working immediately.') }}" dusk="embed-rotate-{{ $site->id }}">
                                {{ __('Rotate key') }}
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" variant="danger-soft" wire:click="remove({{ $site->id }})"
                                         wire:confirm="{{ __('Remove this embed site? Its key stops resolving immediately.') }}" dusk="embed-remove-{{ $site->id }}">
                                {{ __('Remove') }}
                            </x-ui.button>
                        </div>
                    </div>

                    @if ($freshKey !== null && $freshKeySiteId === $site->id)
                        <x-ui.alert variant="info" dusk="embed-fresh-key">
                            <div class="space-y-2 text-sm">
                                <p>{{ __('Copy the site key now — it is shown in full only here:') }} <code class="break-all">{{ $freshKey }}</code></p>
                                <p class="font-medium">{{ __('Iframe / SSI:') }}</p>
                                <pre class="overflow-x-auto rounded bg-surface-sunken p-2 text-xs">&lt;iframe src="{{ route('embed.widget', ['widget' => 'topics']) }}?site={{ $freshKey }}" title="{{ config('app.name') }}" width="360" height="320" loading="lazy" style="border:0"&gt;&lt;/iframe&gt;</pre>
                                <p class="font-medium">{{ __('Web component:') }}</p>
                                <pre class="overflow-x-auto rounded bg-surface-sunken p-2 text-xs">&lt;script src="{{ asset('embed/embed.js') }}" defer&gt;&lt;/script&gt;
&lt;novfora-topics site="{{ $freshKey }}" limit="5"&gt;&lt;/novfora-topics&gt;</pre>
                            </div>
                        </x-ui.alert>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
