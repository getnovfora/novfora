<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\LayoutWidget;
use App\Models\User;
use App\Permissions\Scope;
use App\Theme\LayoutManager;
use App\Theme\WidgetRegistry;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The ACP "Layout" page (ADR-0032) — the visual layout configurator. Admins place widgets into named regions
 * (forum-index top/bottom), reorder, toggle, edit settings, and remove them. Admins-only (admin.access +
 * staff-2FA), re-asserted in mount() AND every action; all writes go through the audited LayoutManager.
 */
new class extends Component
{
    /** @var array<string,string> region => the widget key chosen in its "add" select */
    public array $newWidget = [];

    /** @var array<int,array<string,mixed>> placement id => editable settings */
    public array $settings = [];

    public ?string $status = null;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->loadSettings();
    }

    public function add(string $region): void
    {
        $this->ensureAdmin();
        $key = (string) ($this->newWidget[$region] ?? '');
        if ($key === '' || ! app(WidgetRegistry::class)->has($key) || ! app(LayoutManager::class)->isRegion($region)) {
            return;
        }
        app(LayoutManager::class)->add($region, $key);
        $this->newWidget[$region] = '';
        $this->loadSettings();
        $this->status = __('Widget added.');
    }

    public function save(int $id): void
    {
        $this->ensureAdmin();
        $placement = LayoutWidget::findOrFail($id);
        app(LayoutManager::class)->updateSettings($placement, $this->settings[$id] ?? []);
        $this->status = __('Widget settings saved.');
    }

    public function moveUp(int $id): void
    {
        $this->ensureAdmin();
        app(LayoutManager::class)->move(LayoutWidget::findOrFail($id), -1);
    }

    public function moveDown(int $id): void
    {
        $this->ensureAdmin();
        app(LayoutManager::class)->move(LayoutWidget::findOrFail($id), 1);
    }

    public function toggle(int $id): void
    {
        $this->ensureAdmin();
        $placement = LayoutWidget::findOrFail($id);
        app(LayoutManager::class)->setEnabled($placement, ! $placement->is_enabled);
    }

    public function remove(int $id): void
    {
        $this->ensureAdmin();
        app(LayoutManager::class)->remove(LayoutWidget::findOrFail($id));
        $this->loadSettings();
        $this->status = __('Widget removed.');
    }

    /** @return array<string,string> */
    public function regions(): array
    {
        $this->ensureAdmin();

        return app(LayoutManager::class)->regions();
    }

    /** @return Collection<int,LayoutWidget> */
    public function placements(string $region)
    {
        return app(LayoutManager::class)->placements($region);
    }

    /** @return list<array{key:string,name:string}> */
    public function widgetOptions(): array
    {
        return array_map(
            fn ($w): array => ['key' => $w->key(), 'name' => $w->name()],
            app(WidgetRegistry::class)->all(),
        );
    }

    public function widgetName(string $key): string
    {
        return app(WidgetRegistry::class)->get($key)?->name() ?? $key;
    }

    /** @return list<array{key:string,label:string,type:string,default?:mixed}> */
    public function fieldsFor(string $key): array
    {
        return app(WidgetRegistry::class)->get($key)?->fields() ?? [];
    }

    private function loadSettings(): void
    {
        $this->settings = [];
        foreach (LayoutWidget::all() as $placement) {
            $this->settings[$placement->id] = $placement->settings ?? [];
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

<div class="space-y-5" dusk="admin-layout">
    @if ($status)
        <x-ui.alert variant="success">{{ $status }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Layout & widgets') }}</h2>
            <p class="text-sm text-ink-subtle">{{ __('Place widgets into the page regions below. Widgets render in order; drag the arrows to reorder.') }}</p>
        </div>
    </x-ui.card>

    @foreach ($this->regions() as $regionKey => $regionLabel)
        <x-ui.card class="space-y-4" dusk="region-{{ \Illuminate\Support\Str::slug($regionKey) }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ $regionLabel }}</h3>
                <div class="flex items-center gap-2">
                    <label for="add-{{ \Illuminate\Support\Str::slug($regionKey) }}" class="sr-only">{{ __('Widget') }}</label>
                    <select id="add-{{ \Illuminate\Support\Str::slug($regionKey) }}" wire:model="newWidget.{{ $regionKey }}"
                            class="min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        <option value="">{{ __('Add a widget…') }}</option>
                        @foreach ($this->widgetOptions() as $opt)
                            <option value="{{ $opt['key'] }}">{{ $opt['name'] }}</option>
                        @endforeach
                    </select>
                    <x-ui.button size="sm" variant="primary" wire:click="add('{{ $regionKey }}')" dusk="add-widget">{{ __('Add') }}</x-ui.button>
                </div>
            </div>

            @php($placements = $this->placements($regionKey))
            @if ($placements->isEmpty())
                <p class="text-sm text-ink-subtle">{{ __('No widgets in this region yet.') }}</p>
            @else
                <ul class="space-y-3">
                    @foreach ($placements as $placement)
                        <li class="rounded-lg border border-line bg-surface-sunken p-3" dusk="placement-{{ $placement->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-ink">{{ $this->widgetName($placement->widget_key) }}</span>
                                    @if (! $placement->is_enabled)
                                        <x-ui.badge variant="neutral">{{ __('Disabled') }}</x-ui.badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button type="button" wire:click="moveUp({{ $placement->id }})" class="text-ink-subtle hover:text-ink" title="{{ __('Move up') }}">↑</button>
                                    <button type="button" wire:click="moveDown({{ $placement->id }})" class="text-ink-subtle hover:text-ink" title="{{ __('Move down') }}">↓</button>
                                    <button type="button" wire:click="toggle({{ $placement->id }})" class="text-accent hover:text-accent-hover">{{ $placement->is_enabled ? __('Disable') : __('Enable') }}</button>
                                    <button type="button" wire:click="remove({{ $placement->id }})" class="text-danger hover:underline" dusk="remove-{{ $placement->id }}">{{ __('Remove') }}</button>
                                </div>
                            </div>

                            @php($fields = $this->fieldsFor($placement->widget_key))
                            @if ($fields !== [])
                                <div class="mt-3 space-y-2">
                                    @foreach ($fields as $field)
                                        <label class="block text-xs font-medium text-ink-subtle">{{ $field['label'] }}</label>
                                        @if ($field['type'] === 'textarea')
                                            <textarea wire:model="settings.{{ $placement->id }}.{{ $field['key'] }}" rows="3"
                                                      class="w-full rounded-md bg-surface border border-line text-ink px-3 py-2 focus:border-accent"></textarea>
                                        @elseif ($field['type'] === 'number')
                                            <input type="number" wire:model="settings.{{ $placement->id }}.{{ $field['key'] }}"
                                                   class="w-full min-h-11 rounded-md bg-surface border border-line text-ink px-3 focus:border-accent">
                                        @else
                                            <input type="text" wire:model="settings.{{ $placement->id }}.{{ $field['key'] }}"
                                                   class="w-full min-h-11 rounded-md bg-surface border border-line text-ink px-3 focus:border-accent">
                                        @endif
                                    @endforeach
                                    <x-ui.button size="sm" variant="subtle" wire:click="save({{ $placement->id }})" dusk="save-{{ $placement->id }}">{{ __('Save settings') }}</x-ui.button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endforeach
</div>
