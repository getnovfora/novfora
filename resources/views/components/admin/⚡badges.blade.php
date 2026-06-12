<?php
// SPDX-License-Identifier: Apache-2.0
use App\Community\BadgeException;
use App\Community\BadgeManager;
use App\Community\BadgeService;
use App\Models\Badge;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\GroupColor;
use Livewire\Component;

/**
 * Admin → Badges — the badge manager: list / create / edit / delete badges, and configure their
 * name, slug, description, criteria, icon, colour, and active state. Like every admin SFC the
 * authorization is re-asserted in mount() AND every action, because Livewire actions reach the
 * component via livewire/update with no route middleware. badge.manage is an additional permission
 * check beyond admin.access so it can be delegated independently in the future.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating

    public string $name = '';

    public string $description = '';

    public string $criteriaType = 'post_count';

    public int $threshold = 5;

    public string $iconToken = ''; // '' = none

    public string $colorToken = ''; // '' = none

    public bool $isActive = true;

    public ?int $deleteId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function newBadge(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $badge = Badge::findOrFail($id);
        $this->formId = $badge->id;
        $this->name = (string) $badge->name;
        $this->description = (string) ($badge->description ?? '');
        $criteria = (array) $badge->criteria;
        $this->criteriaType = (string) ($criteria['type'] ?? 'post_count');
        $this->threshold = (int) ($criteria['threshold'] ?? 5);
        $this->iconToken = (string) ($badge->icon_token ?? '');
        $this->colorToken = (string) ($badge->color_token ?? '');
        $this->isActive = (bool) $badge->is_active;
        $this->deleteId = null;
        $this->showForm = true;
    }

    public function save(BadgeManager $manager): void
    {
        $this->ensureAdmin();

        $validIconTokens = array_merge([''], BadgeManager::ICON_TOKENS);
        $validColorTokens = array_merge([''], array_keys(GroupColor::PALETTE));
        $validCriteriaTypes = BadgeService::CRITERIA_TYPES;

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'], // the column is VARCHAR(255) — strict MySQL rejects, never truncates
            'criteriaType' => ['required', 'string', 'in:'.implode(',', $validCriteriaTypes)],
            'iconToken' => ['nullable', 'string', 'in:'.implode(',', $validIconTokens)],
            'colorToken' => ['nullable', 'string', 'in:'.implode(',', $validColorTokens)],
        ];
        // join carries no threshold AND must not validate the hidden field — a stale out-of-range value
        // left from a previous type selection would otherwise fail invisibly and the save would no-op.
        if ($this->criteriaType !== 'join') {
            $rules['threshold'] = ['required', 'integer', 'min:1'];
        }
        $this->validate($rules);

        // Build the normalised criteria document: join carries no threshold.
        $criteria = ['type' => $this->criteriaType];
        if ($this->criteriaType !== 'join') {
            $criteria['threshold'] = $this->threshold;
        }

        $payload = [
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
            'criteria' => $criteria,
            'icon_token' => $this->iconToken !== '' ? $this->iconToken : null,
            'color_token' => $this->colorToken !== '' ? $this->colorToken : null,
            'is_active' => $this->isActive,
        ];

        try {
            if ($this->formId === null) {
                $badge = $manager->create($payload);
                $this->flash('Created badge "'.$badge->name.'".', 'success');
            } else {
                $badge = $manager->update(Badge::findOrFail($this->formId), $payload);
                $this->flash('Saved badge "'.$badge->name.'".', 'success');
            }
            $this->cancelForm();
        } catch (BadgeException $e) {
            $this->addError('name', $e->getMessage());
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function askDelete(int $id): void
    {
        $this->ensureAdmin();
        $this->deleteId = $id;
        $this->showForm = false;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
    }

    public function delete(BadgeManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === null) {
            return;
        }

        $badge = Badge::findOrFail($this->deleteId);

        try {
            $manager->delete($badge);
            $this->flash('Deleted "'.$badge->name.'".', 'success');
            $this->deleteId = null;
        } catch (BadgeException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Badge> */
    public function rows(): \Illuminate\Database\Eloquent\Collection
    {
        $this->ensureAdmin();

        // withCount('users') avoids N+1 when rendering the awarded count per row.
        return Badge::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    public function colorOptions(): array
    {
        return GroupColor::PALETTE;
    }

    /** @return list<string> */
    public function iconOptions(): array
    {
        return BadgeManager::ICON_TOKENS;
    }

    /** Human-readable summary of a criteria document for display in the listing. */
    public function criteriaSummary(Badge $badge): string
    {
        $criteria = (array) $badge->criteria;
        $type = $criteria['type'] ?? '';
        $threshold = (int) ($criteria['threshold'] ?? 0);

        return match ($type) {
            'join' => 'Join',
            'post_count' => 'Posts ≥ '.$threshold,
            'reputation' => 'Reputation ≥ '.$threshold,
            default => ucfirst($type),
        };
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'name', 'description', 'iconToken', 'colorToken']);
        $this->criteriaType = 'post_count';
        $this->threshold = 5;
        $this->isActive = true;
        $this->resetErrorBag();
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('badge.manage', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="acp-badges">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            Badges are awarded to members who meet defined criteria (joining, reaching a post count, or a
            reputation threshold). Active badges are evaluated automatically; inactive badges are skipped
            during award evaluation but remain on members who already hold them.
        </p>
        <x-ui.button type="button" size="sm" wire:click="newBadge" dusk="badge-new">
            <x-ui.icon name="plus" class="h-4 w-4" /> New badge
        </x-ui.button>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-sm font-semibold text-ink">{{ $formId ? 'Edit badge' : 'New badge' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" name="name" wire:model="name" required maxlength="100" dusk="badge-name" />
                    <x-ui.input label="Description" name="description" wire:model="description" maxlength="500"
                                hint="Optional. Shown on the member's profile." />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.select label="Criteria type" name="criteriaType" wire:model.live="criteriaType">
                        @foreach (\App\Community\BadgeService::CRITERIA_TYPES as $type)
                            <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </x-ui.select>

                    @if ($criteriaType !== 'join')
                        <x-ui.input label="Threshold" name="threshold" type="number" min="1" wire:model="threshold"
                                    hint="{{ $criteriaType === 'post_count' ? 'Minimum post count to earn this badge.' : 'Minimum reputation points to earn this badge.' }}" />
                    @else
                        <div>
                            <p class="text-sm font-medium text-ink mb-1">Threshold</p>
                            <p class="text-sm text-ink-muted mt-2">No threshold — awarded on join.</p>
                        </div>
                    @endif

                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm text-ink cursor-pointer">
                            <input type="checkbox" wire:model="isActive" class="rounded border-line" />
                            Active
                        </label>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select label="Icon" name="iconToken" wire:model.live="iconToken"
                                 hint="Optional decorative icon displayed with the badge.">
                        <option value="">— No icon —</option>
                        @foreach ($this->iconOptions() as $icon)
                            <option value="{{ $icon }}">{{ $icon }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Colour" name="colorToken" wire:model.live="colorToken">
                        <option value="">— No colour —</option>
                        @foreach ($this->colorOptions() as $key => $meta)
                            <option value="{{ $key }}">{{ $meta[0] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                {{-- Preview chip: colored dot + name, using the CSS custom property when a color is set. --}}
                @if ($name !== '' || $colorToken !== '')
                    @php($previewColor = \App\Support\GroupColor::cssVar($colorToken))
                    <p class="text-sm text-ink-muted">
                        Preview:
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border border-line"
                              @if ($previewColor) style="color: {{ $previewColor }}; border-color: {{ $previewColor }}40;" @endif>
                            @if ($iconToken !== '')
                                <x-ui.icon :name="$iconToken" class="h-3.5 w-3.5" />
                            @elseif ($previewColor)
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full" style="background: {{ $previewColor }};" aria-hidden="true"></span>
                            @endif
                            {{ $name !== '' ? $name : 'Badge name' }}
                        </span>
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="badge-save">
                        <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create badge' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Badge list. --}}
    <x-ui.card flush>
        <div class="hidden sm:grid grid-cols-[1fr_9rem_7rem_6rem_7rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            <span>Badge</span>
            <span>Criteria</span>
            <span class="text-right">Awarded</span>
            <span class="text-center">Status</span>
            <span class="text-right">Actions</span>
        </div>
        @php($rows = $this->rows())
        @if ($rows->isEmpty())
            <x-ui.empty title="No badges yet">
                <x-slot:icon><x-ui.icon name="check-circle" class="h-6 w-6" /></x-slot:icon>
                Create your first badge above to start recognising member achievements.
            </x-ui.empty>
        @else
            <ul class="divide-y divide-line">
                @foreach ($rows as $badge)
                    <li dusk="badge-row-{{ $badge->id }}">
                        <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[1fr_9rem_7rem_6rem_7rem] sm:items-center sm:gap-3 sm:px-5 text-sm">
                            {{-- Name chip with optional color + icon. --}}
                            <div class="min-w-0">
                                @php($bc = \App\Support\GroupColor::cssVar($badge->color_token))
                                <div class="flex items-center gap-2">
                                    @if ($badge->icon_token)
                                        <x-ui.icon :name="$badge->icon_token" class="h-4 w-4 shrink-0"
                                                   @if ($bc) style="color: {{ $bc }};" @endif aria-hidden="true" />
                                    @elseif ($bc)
                                        <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $bc }};" aria-hidden="true"></span>
                                    @endif
                                    <span class="font-medium truncate" @if ($bc) style="color: {{ $bc }};" @endif>{{ $badge->name }}</span>
                                </div>
                                @if ($badge->description)
                                    <p class="text-xs text-ink-muted mt-0.5 truncate">{{ $badge->description }}</p>
                                @endif
                            </div>

                            {{-- Criteria summary. --}}
                            <div class="text-ink-muted text-xs nums">{{ $this->criteriaSummary($badge) }}</div>

                            {{-- Awarded count (preloaded via withCount, no N+1). --}}
                            <div class="text-ink-muted sm:text-right nums">{{ number_format($badge->users_count) }}</div>

                            {{-- Active / Inactive status chip. --}}
                            <div class="sm:text-center">
                                <x-ui.badge :variant="$badge->is_active ? 'success' : 'neutral'">
                                    {{ $badge->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </div>

                            {{-- Edit + delete actions. --}}
                            <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                                <x-ui.button type="button" variant="ghost" size="sm" icon
                                             wire:click="edit({{ $badge->id }})" title="Edit"
                                             dusk="badge-edit-{{ $badge->id }}">
                                    <x-ui.icon name="pencil" class="h-4 w-4" />
                                </x-ui.button>
                                <x-ui.button type="button" variant="danger-ghost" size="sm" icon
                                             wire:click="askDelete({{ $badge->id }})" title="Delete"
                                             dusk="badge-delete-{{ $badge->id }}">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Inline delete-safety panel. --}}
                        @if ($deleteId === $badge->id)
                            <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                                <x-ui.alert variant="warn" class="mb-3">
                                    Delete "{{ $badge->name }}"? The badge definition AND every member's award of it are
                                    permanently removed — it disappears from their profiles. To retire a badge while
                                    existing holders keep it, mark it inactive instead.
                                </x-ui.alert>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="button" variant="danger" wire:click="delete"
                                                 wire:loading.attr="disabled" wire:target="delete"
                                                 dusk="badge-delete-confirm">
                                        <span wire:loading.remove wire:target="delete">Delete</span>
                                        <span wire:loading wire:target="delete">Working…</span>
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
