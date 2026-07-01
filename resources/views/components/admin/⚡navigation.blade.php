<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Group;
use App\Models\NavigationItem;
use App\Models\User;
use App\Navigation\NavigationManager;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Appearance -> Navigation. Public menu items are admin-editable, shallowly nestable, ordered, visibility-gated,
 * and written only through NavigationManager so the layout never owns persistence rules.
 */
new class extends Component
{
    /** @var array<string,mixed> */
    public array $form = [];

    public ?int $editingId = null;

    public ?string $status = null;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
    }

    public function save(): void
    {
        $this->ensureAdmin();
        $data = $this->validatedForm();

        if ($this->editingId !== null) {
            app(NavigationManager::class)->update(NavigationItem::findOrFail($this->editingId), $data);
            $this->status = __('Navigation item updated.');
        } else {
            app(NavigationManager::class)->create($data);
            $this->status = __('Navigation item added.');
        }

        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $item = NavigationItem::findOrFail($id);
        $this->editingId = (int) $item->id;
        $this->form = [
            'title' => $item->title,
            'link_type' => $item->link_type,
            'route_name' => $item->route_name,
            'url' => $item->url,
            'icon' => $item->icon ?? '',
            'parent_id' => $item->parent_id ? (string) $item->parent_id : '',
            'visibility' => $item->visibility,
            'group_ids' => array_map('strval', $item->group_ids ?? []),
            'is_enabled' => (bool) $item->is_enabled,
            'show_on_desktop' => (bool) $item->show_on_desktop,
            'show_on_mobile' => (bool) $item->show_on_mobile,
            'opens_new_tab' => (bool) $item->opens_new_tab,
        ];
        $this->status = null;
    }

    public function cancel(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
    }

    public function moveUp(int $id): void
    {
        $this->ensureAdmin();
        app(NavigationManager::class)->move(NavigationItem::findOrFail($id), -1);
    }

    public function moveDown(int $id): void
    {
        $this->ensureAdmin();
        app(NavigationManager::class)->move(NavigationItem::findOrFail($id), 1);
    }

    public function toggle(int $id): void
    {
        $this->ensureAdmin();
        $item = NavigationItem::findOrFail($id);
        app(NavigationManager::class)->setEnabled($item, ! $item->is_enabled);
    }

    public function remove(int $id): void
    {
        $this->ensureAdmin();
        app(NavigationManager::class)->remove(NavigationItem::findOrFail($id));
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        $this->status = __('Navigation item removed.');
    }

    /** @return list<array{item:NavigationItem,depth:int}> */
    public function flatItems(): array
    {
        $this->ensureAdmin();
        $items = [];
        $roots = NavigationItem::query()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($roots as $root) {
            $items[] = ['item' => $root, 'depth' => 0];
            foreach ($root->children as $child) {
                $items[] = ['item' => $child, 'depth' => 1];
            }
        }

        return $items;
    }

    /** @return Collection<int, NavigationItem> */
    public function parentOptions(): Collection
    {
        $this->ensureAdmin();

        return NavigationItem::query()
            ->whereNull('parent_id')
            ->when($this->editingId !== null, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Group> */
    public function groupOptions(): Collection
    {
        $this->ensureAdmin();

        return Group::query()->orderByDesc('priority')->orderBy('name')->get();
    }

    /** @return array<string,string> */
    public function routeOptions(): array
    {
        return array_filter(
            NavigationManager::ROUTE_OPTIONS,
            fn (string $label, string $name): bool => Route::has($name),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<string,string> */
    public function visibilityOptions(): array
    {
        return NavigationManager::VISIBILITY_OPTIONS;
    }

    /** @return list<string> */
    public function iconOptions(): array
    {
        return NavigationManager::ICON_OPTIONS;
    }

    public function summary(NavigationItem $item): string
    {
        if ($item->link_type === NavigationManager::LINK_ROUTE) {
            return $item->route_name ?? __('No route selected.');
        }

        return $item->url ?? __('No URL selected.');
    }

    public function visibilitySummary(NavigationItem $item): string
    {
        $label = NavigationManager::VISIBILITY_OPTIONS[$item->visibility] ?? NavigationManager::VISIBILITY_OPTIONS[NavigationManager::VISIBILITY_EVERYONE];
        if ($item->visibility !== NavigationManager::VISIBILITY_GROUPS) {
            return $label;
        }

        $names = Group::query()->whereIn('id', $item->group_ids ?? [])->orderByDesc('priority')->orderBy('name')->pluck('name')->all();

        return $names === [] ? __('Selected groups (none chosen)') : __('Groups: :groups', ['groups' => implode(', ', $names)]);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'title' => '',
            'link_type' => NavigationManager::LINK_ROUTE,
            'route_name' => 'forums.index',
            'url' => '',
            'icon' => '',
            'parent_id' => '',
            'visibility' => NavigationManager::VISIBILITY_EVERYONE,
            'group_ids' => [],
            'is_enabled' => true,
            'show_on_desktop' => true,
            'show_on_mobile' => true,
            'opens_new_tab' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function validatedForm(): array
    {
        $rules = [
            'form.title' => ['required', 'string', 'max:80'],
            'form.link_type' => ['required', Rule::in([NavigationManager::LINK_ROUTE, NavigationManager::LINK_URL])],
            'form.icon' => ['nullable', Rule::in(NavigationManager::ICON_OPTIONS)],
            'form.parent_id' => ['nullable', 'integer', 'exists:navigation_items,id'],
            'form.visibility' => ['required', Rule::in(array_keys(NavigationManager::VISIBILITY_OPTIONS))],
            'form.group_ids' => ['array'],
            'form.group_ids.*' => ['integer', 'exists:groups,id'],
            'form.is_enabled' => ['boolean'],
            'form.show_on_desktop' => ['boolean'],
            'form.show_on_mobile' => ['boolean'],
            'form.opens_new_tab' => ['boolean'],
        ];

        if (($this->form['link_type'] ?? '') === NavigationManager::LINK_URL) {
            $rules['form.url'] = ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->validPublicUrl((string) $value)) {
                    $fail(__('Use a relative path starting with /, or an http(s) URL.'));
                }
            }];
            $rules['form.route_name'] = ['nullable'];
        } else {
            $rules['form.route_name'] = ['required', Rule::in(array_keys($this->routeOptions()))];
            $rules['form.url'] = ['nullable'];
        }

        /** @var array{form:array<string,mixed>} $validated */
        $validated = $this->validate($rules);

        return $validated['form'];
    }

    private function validPublicUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[[:cntrl:]]/', $url) === 1) {
            return false;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return true;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_unless($user instanceof User && $user->canDo('admin.appearance.access', Scope::global()), 403);
        abort_if($user->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="admin-navigation">
    @if ($status)
        <x-ui.alert variant="success">{{ $status }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-ink">{{ __('Navigation') }}</h2>
            <p class="text-sm text-ink-subtle">{{ __('Manage the public header menu. Items can be ordered, nested one level deep, hidden per device, and limited by audience.') }}</p>
        </div>
    </x-ui.card>

    <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <x-ui.card class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ __('Menu items') }}</h3>
                <x-ui.badge variant="neutral">{{ __('Top navigation') }}</x-ui.badge>
            </div>

            @php($rows = $this->flatItems())
            @if ($rows === [])
                <p class="text-sm text-ink-subtle">{{ __('No navigation items yet. Add the first item with the form.') }}</p>
            @else
                <ul class="space-y-3">
                    @foreach ($rows as $row)
                        @php($item = $row['item'])
                        <li class="rounded-lg border border-line bg-surface-sunken p-3 {{ $row['depth'] === 1 ? 'ml-6' : '' }}" dusk="navigation-item-{{ $item->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($row['depth'] === 1)
                                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('Child') }}</span>
                                        @endif
                                        @if ($item->icon)
                                            <x-ui.icon :name="$item->icon" class="h-4 w-4 text-ink-subtle" />
                                        @endif
                                        <span class="font-medium text-ink">{{ $item->title }}</span>
                                        @if (! $item->is_enabled)
                                            <x-ui.badge variant="neutral">{{ __('Disabled') }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="break-all text-xs text-ink-subtle">{{ $this->summary($item) }}</p>
                                    <p class="text-xs text-ink-subtle">
                                        {{ $this->visibilitySummary($item) }}
                                        <span aria-hidden="true">&middot;</span>
                                        {{ $item->show_on_desktop ? __('Desktop') : __('Hidden on desktop') }}
                                        <span aria-hidden="true">&middot;</span>
                                        {{ $item->show_on_mobile ? __('Mobile') : __('Hidden on mobile') }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button type="button" wire:click="moveUp({{ $item->id }})" class="text-ink-subtle hover:text-ink" title="{{ __('Move up') }}">{{ __('Up') }}</button>
                                    <button type="button" wire:click="moveDown({{ $item->id }})" class="text-ink-subtle hover:text-ink" title="{{ __('Move down') }}">{{ __('Down') }}</button>
                                    <button type="button" wire:click="toggle({{ $item->id }})" class="text-accent hover:text-accent-hover">{{ $item->is_enabled ? __('Disable') : __('Enable') }}</button>
                                    <button type="button" wire:click="edit({{ $item->id }})" class="text-accent hover:text-accent-hover">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="remove({{ $item->id }})" class="text-danger hover:underline">{{ __('Remove') }}</button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-ink">{{ $editingId ? __('Edit item') : __('Add item') }}</h3>
                <p class="text-xs text-ink-subtle">{{ __('Use custom URLs for destinations outside the allowlisted public routes.') }}</p>
            </div>

            <form wire:submit="save" class="space-y-4">
                <div>
                    <label for="nav-title" class="block text-xs font-medium text-ink-subtle">{{ __('Label') }}</label>
                    <input id="nav-title" type="text" wire:model="form.title"
                           class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                    @error('form.title') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="nav-link-type" class="block text-xs font-medium text-ink-subtle">{{ __('Link type') }}</label>
                        <select id="nav-link-type" wire:model.live="form.link_type"
                                class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                            <option value="route">{{ __('Route') }}</option>
                            <option value="url">{{ __('Custom URL') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="nav-icon" class="block text-xs font-medium text-ink-subtle">{{ __('Icon') }}</label>
                        <select id="nav-icon" wire:model="form.icon"
                                class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                            @foreach ($this->iconOptions() as $icon)
                                <option value="{{ $icon }}">{{ $icon === '' ? __('No icon') : $icon }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if (($form['link_type'] ?? 'route') === 'url')
                    <div>
                        <label for="nav-url" class="block text-xs font-medium text-ink-subtle">{{ __('URL') }}</label>
                        <input id="nav-url" type="text" wire:model="form.url" placeholder="/help or https://example.com"
                               class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                        @error('form.url') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="nav-route" class="block text-xs font-medium text-ink-subtle">{{ __('Route') }}</label>
                        <select id="nav-route" wire:model="form.route_name"
                                class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                            @foreach ($this->routeOptions() as $name => $label)
                                <option value="{{ $name }}">{{ $label }} ({{ $name }})</option>
                            @endforeach
                        </select>
                        @error('form.route_name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="nav-parent" class="block text-xs font-medium text-ink-subtle">{{ __('Parent') }}</label>
                    <select id="nav-parent" wire:model="form.parent_id"
                            class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                        <option value="">{{ __('Top level') }}</option>
                        @foreach ($this->parentOptions() as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->title }}</option>
                        @endforeach
                    </select>
                    @error('form.parent_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="nav-visibility" class="block text-xs font-medium text-ink-subtle">{{ __('Visibility') }}</label>
                    <select id="nav-visibility" wire:model.live="form.visibility"
                            class="mt-1 w-full min-h-11 rounded-md bg-surface border border-line px-3 text-ink focus:border-accent">
                        @foreach ($this->visibilityOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if (($form['visibility'] ?? '') === \App\Navigation\NavigationManager::VISIBILITY_GROUPS)
                    <fieldset class="space-y-2">
                        <legend class="text-xs font-medium text-ink-subtle">{{ __('Allowed groups') }}</legend>
                        <div class="max-h-40 space-y-1 overflow-y-auto rounded-md border border-line p-2">
                            @foreach ($this->groupOptions() as $group)
                                <label class="flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" wire:model="form.group_ids" value="{{ $group->id }}" class="rounded border-line text-accent focus:ring-accent">
                                    <span>{{ $group->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('form.group_ids.*') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                <fieldset class="space-y-2">
                    <legend class="text-xs font-medium text-ink-subtle">{{ __('Options') }}</legend>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="form.is_enabled" class="rounded border-line text-accent focus:ring-accent">
                        <span>{{ __('Enabled') }}</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="form.show_on_desktop" class="rounded border-line text-accent focus:ring-accent">
                        <span>{{ __('Show on desktop') }}</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="form.show_on_mobile" class="rounded border-line text-accent focus:ring-accent">
                        <span>{{ __('Show on mobile') }}</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="form.opens_new_tab" class="rounded border-line text-accent focus:ring-accent">
                        <span>{{ __('Open in a new tab') }}</span>
                    </label>
                </fieldset>

                <div class="flex items-center gap-2">
                    <x-ui.button type="submit" size="sm" variant="primary">{{ $editingId ? __('Save item') : __('Add item') }}</x-ui.button>
                    @if ($editingId)
                        <x-ui.button type="button" size="sm" variant="subtle" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
