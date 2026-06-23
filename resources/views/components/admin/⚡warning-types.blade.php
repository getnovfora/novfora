<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Models\WarningType;
use App\Permissions\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Admin → Moderation → Warning types (ACP v4 · A3 · ADR-0096). CRUD over the existing `warning_types` engine
 * (label · points · decay days · default consequence action · active), plus a READ-ONLY view of the cumulative
 * consequence thresholds (config). No engine change — this only surfaces what WarningService already consumes.
 * Self-guards admin.access + bans.manage + staff-2FA on mount and every action (warnings are bans.manage,
 * the same capability the front-end WarningController uses).
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $label = '';

    public int $points = 1;

    public ?int $decayDays = 30;

    public string $action = ''; // '' | restrict | moderate | temp_ban | ban

    public ?int $actionDays = 7;

    public bool $isActive = true;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->ensureCanManage();
    }

    public function create(): void
    {
        $this->ensureCanManage();
        $this->reset(['editingId', 'label', 'action']);
        $this->points = 1;
        $this->decayDays = 30;
        $this->actionDays = 7;
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureCanManage();
        $t = WarningType::findOrFail($id);
        $this->editingId = $t->id;
        $this->label = (string) $t->label;
        $this->points = (int) $t->default_points;
        $this->decayDays = $t->decay_days !== null ? (int) $t->decay_days : null;
        $this->action = (string) ($t->default_action['action'] ?? '');
        $this->actionDays = (int) ($t->default_action['days'] ?? 7);
        $this->isActive = (bool) $t->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'points' => ['required', 'integer', 'min:0', 'max:1000'],
            'decayDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'action' => ['nullable', 'in:restrict,moderate,temp_ban,ban'],
            'actionDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $defaultAction = $this->action !== ''
            ? array_filter(
                ['action' => $this->action, 'days' => $this->action === 'temp_ban' ? (int) ($this->actionDays ?: 7) : null],
                fn ($v) => $v !== null,
            )
            : null;

        $attrs = [
            'label' => $this->label,
            'default_points' => (int) $this->points,
            'decay_days' => $this->decayDays,
            'default_action' => $defaultAction,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId !== null) {
            WarningType::findOrFail($this->editingId)->update($attrs);
            $this->flash = 'Warning type updated.';
        } else {
            $attrs['slug'] = $this->uniqueSlug($this->label);
            WarningType::create($attrs);
            $this->flash = 'Warning type created.';
        }

        $this->showForm = false;
        $this->reset(['editingId', 'label', 'action']);
    }

    public function toggleActive(int $id): void
    {
        $this->ensureCanManage();
        $t = WarningType::findOrFail($id);
        $t->update(['is_active' => ! $t->is_active]);
    }

    public function delete(int $id): void
    {
        $this->ensureCanManage();
        // warnings.warning_type_id has no FK constraint, so existing warnings simply fall back to the generic
        // "Warning" label (Warning::type() returns null) — deleting a type never orphans or errors a record.
        WarningType::whereKey($id)->delete();
        $this->flash = 'Warning type deleted.';
    }

    /** @return Collection<int, WarningType> */
    public function types(): Collection
    {
        $this->ensureCanManage();

        return WarningType::orderByDesc('is_active')->orderBy('default_points')->get();
    }

    /** @return array<string, int> the cumulative consequence thresholds (read-only; config-tunable via env). */
    public function thresholds(): array
    {
        return (array) config('novfora.antispam.warnings.thresholds', []);
    }

    private function uniqueSlug(string $label): string
    {
        $base = Str::slug($label) ?: 'warning';
        $slug = $base;
        $i = 2;
        while (WarningType::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function ensureCanManage(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('bans.manage', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    @if ($flash)
        <div class="rounded-md border border-success/40 bg-success-soft px-4 py-2.5 text-sm text-success-ink" dusk="wt-flash">{{ $flash }}</div>
    @endif

    {{-- Consequence thresholds (read-only — these live in config/env, consumed by WarningService) --}}
    <x-ui.card>
        <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle mb-2">Consequence thresholds</h3>
        <p class="text-sm text-ink-muted mb-3">When a member’s cumulative <em>live</em> warning points cross these totals, the consequence applies automatically. (Set via config / environment.)</p>
        <div class="grid gap-3 sm:grid-cols-3 text-sm">
            <div class="rounded-md border border-line p-3"><dt class="text-ink-subtle">Moderate (posts held)</dt><dd class="text-ink text-lg font-semibold">≥ {{ (int) ($this->thresholds()['moderate'] ?? 0) }} pts</dd></div>
            <div class="rounded-md border border-line p-3"><dt class="text-ink-subtle">Temporary ban</dt><dd class="text-ink text-lg font-semibold">≥ {{ (int) ($this->thresholds()['temp_ban'] ?? 0) }} pts</dd></div>
            <div class="rounded-md border border-line p-3"><dt class="text-ink-subtle">Permanent ban</dt><dd class="text-ink text-lg font-semibold">≥ {{ (int) ($this->thresholds()['ban'] ?? 0) }} pts</dd></div>
        </div>
    </x-ui.card>

    {{-- Warning types --}}
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-ink">Warning types</h2>
        <x-ui.button size="sm" wire:click="create" dusk="wt-create">New type</x-ui.button>
    </div>

    @if ($showForm)
        <x-ui.card>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="wt-label" class="block text-sm font-medium text-ink mb-1.5">Label</label>
                    <input id="wt-label" wire:model="label" maxlength="100"
                           class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    @error('label') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="wt-points" class="block text-sm font-medium text-ink mb-1.5">Points</label>
                    <input id="wt-points" type="number" min="0" max="1000" wire:model="points"
                           class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    @error('points') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="wt-decay" class="block text-sm font-medium text-ink mb-1.5">Decay days (blank = never)</label>
                    <input id="wt-decay" type="number" min="1" max="3650" wire:model="decayDays"
                           class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    @error('decayDays') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="wt-action" class="block text-sm font-medium text-ink mb-1.5">Default action</label>
                    <select id="wt-action" wire:model.live="action"
                            class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                        <option value="">None (points only)</option>
                        <option value="restrict">Restrict</option>
                        <option value="moderate">Moderate (hold posts)</option>
                        <option value="temp_ban">Temporary ban</option>
                        <option value="ban">Permanent ban</option>
                    </select>
                </div>
                @if ($action === 'temp_ban')
                    <div>
                        <label for="wt-action-days" class="block text-sm font-medium text-ink mb-1.5">Temp-ban days</label>
                        <input id="wt-action-days" type="number" min="1" max="3650" wire:model="actionDays"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    </div>
                @endif
                <div class="sm:col-span-2 flex items-center gap-2">
                    <input id="wt-active" type="checkbox" wire:model="isActive" class="rounded border-line text-accent">
                    <label for="wt-active" class="text-sm text-ink">Active</label>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <x-ui.button size="sm" wire:click="save" dusk="wt-save">{{ $editingId ? 'Save changes' : 'Create type' }}</x-ui.button>
                <x-ui.button size="sm" variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.table label="Warning types">
        <x-slot:head>
            <tr><th>Label</th><th>Points</th><th>Decay</th><th>Action</th><th>Status</th><th class="text-right">Manage</th></tr>
        </x-slot:head>
        @forelse ($this->types() as $t)
            <tr wire:key="wt-{{ $t->id }}">
                <td class="text-ink font-medium">{{ $t->label }}</td>
                <td class="text-ink-muted">{{ (int) $t->default_points }}</td>
                <td class="text-ink-muted">{{ $t->decay_days !== null ? $t->decay_days.'d' : 'never' }}</td>
                <td class="text-ink-muted">
                    @php($act = $t->default_action['action'] ?? null)
                    {{ $act === 'temp_ban' ? 'Temp ban ('.((int) ($t->default_action['days'] ?? 0)).'d)' : ($act ? ucfirst($act) : '—') }}
                </td>
                <td><x-ui.badge :variant="$t->is_active ? 'success' : 'neutral'">{{ $t->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                <td class="text-right whitespace-nowrap">
                    <x-ui.button size="sm" :variant="$t->is_active ? 'danger-soft' : 'ghost'" wire:click="toggleActive({{ $t->id }})">{{ $t->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" icon wire:click="edit({{ $t->id }})" title="Edit" dusk="wt-edit-{{ $t->id }}"><x-ui.icon name="pencil" class="h-4 w-4" /></x-ui.button>
                    <x-ui.button size="sm" variant="danger-ghost" icon wire:click="delete({{ $t->id }})" title="Delete" wire:confirm="Delete this warning type? Existing warnings keep their points." dusk="wt-delete-{{ $t->id }}"><x-ui.icon name="trash" class="h-4 w-4" /></x-ui.button>
                </td>
            </tr>
        @empty
            <tr><td colspan="6">
                <x-ui.empty title="No warning types yet.">
                    <x-slot:icon><x-ui.icon name="shield" class="h-6 w-6" /></x-slot:icon>
                    Create one to start issuing typed warnings.
                </x-ui.empty>
            </td></tr>
        @endforelse
    </x-ui.table>
</div>
