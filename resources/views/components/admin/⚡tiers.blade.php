<?php
// SPDX-License-Identifier: Apache-2.0
use App\Membership\TierPerks;
use App\Models\MembershipTier;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Admin → Membership tiers (Phase 4 · M5.1). List / create / edit / delete membership tiers and choose the
 * perks each grants (from the fixed TierPerks universe). Authorization is re-asserted in mount() AND every
 * action (Livewire actions reach the component with no route middleware). This page never charges money —
 * granting a member happens via the manual provider (M5.2) or Stripe (M5.3).
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null; // null = creating

    public string $name = '';

    public string $description = '';

    public string $price = '0.00';

    public string $currency = 'USD';

    public string $interval = 'monthly';

    /** @var array<int,string> */
    public array $perks = [];

    public bool $isActive = true;

    public int $sort = 0;

    public ?int $deleteId = null;

    public ?string $saved = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function create(): void
    {
        $this->ensureAdmin();
        $this->reset(['formId', 'name', 'description', 'currency', 'interval', 'perks', 'sort']);
        $this->price = '0.00';
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $tier = MembershipTier::findOrFail($id);
        $this->formId = (int) $tier->id;
        $this->name = (string) $tier->name;
        $this->description = (string) ($tier->description ?? '');
        $this->price = number_format($tier->price_cents / 100, 2, '.', '');
        $this->currency = (string) $tier->currency;
        $this->interval = (string) $tier->interval;
        $this->perks = $tier->perkKeys();
        $this->isActive = (bool) $tier->is_active;
        $this->sort = (int) $tier->sort;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', 'in:one_time,monthly,yearly'],
            'perks' => ['array'],
            'perks.*' => ['string', 'in:'.implode(',', TierPerks::keys())],
            'isActive' => ['boolean'],
            'sort' => ['integer', 'min:0', 'max:9999'],
        ]);

        $attrs = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_cents' => (int) round(((float) $data['price']) * 100),
            'currency' => mb_strtoupper($data['currency']),
            'interval' => $data['interval'],
            'perks' => TierPerks::sanitize($this->perks),
            'is_active' => (bool) $this->isActive,
            'sort' => (int) $this->sort,
        ];

        if ($this->formId !== null) {
            MembershipTier::findOrFail($this->formId)->update($attrs);
        } else {
            $attrs['slug'] = $this->uniqueSlug($data['name']);
            MembershipTier::create($attrs);
        }

        $this->showForm = false;
        $this->saved = 'Saved.';
    }

    public function delete(int $id): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === $id) {
            MembershipTier::findOrFail($id)->delete();
            $this->deleteId = null;
            $this->saved = 'Tier deleted.';
        } else {
            $this->deleteId = $id; // arm
        }
    }

    /** @return \Illuminate\Support\Collection<int,MembershipTier> */
    public function tiers()
    {
        return MembershipTier::query()->orderBy('sort')->orderBy('name')->get();
    }

    /** @return array<string,string> */
    public function perkOptions(): array
    {
        return TierPerks::ALL;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tier';
        $slug = $base;
        $i = 1;
        while (MembershipTier::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    @if (! $showForm)
        <div class="flex items-center justify-between">
            <p class="text-sm text-ink-muted">Membership tiers grant perks through the permission engine. No money is charged here.</p>
            <x-ui.button wire:click="create">New tier</x-ui.button>
        </div>

        <div class="space-y-2">
            @forelse ($this->tiers() as $tier)
                <x-ui.card>
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ $tier->name }}
                                @unless ($tier->is_active) <span class="text-xs text-ink-subtle">(inactive)</span> @endunless
                            </h3>
                            <p class="text-xs text-ink-muted">{{ $tier->priceLabel() }} · {{ count($tier->perkKeys()) }} perk(s)</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="subtle" wire:click="edit({{ $tier->id }})">Edit</x-ui.button>
                            <x-ui.button variant="danger-ghost" wire:click="delete({{ $tier->id }})">
                                {{ $deleteId === $tier->id ? 'Confirm delete' : 'Delete' }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <p class="text-sm text-ink-subtle">No tiers yet.</p>
            @endforelse
        </div>
    @else
        <form wire:submit="save" class="space-y-5">
            <x-ui.input label="Name" name="name" wire:model="name" />
            <x-ui.textarea label="Description" name="description" wire:model="description" />
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input label="Price" name="price" wire:model="price" hint="In major units, e.g. 5.00" />
                <x-ui.input label="Currency" name="currency" wire:model="currency" maxlength="3" />
                <x-ui.select label="Interval" name="interval" wire:model="interval">
                    <option value="one_time">One-time</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </x-ui.select>
            </div>

            <fieldset class="space-y-2 border-t border-line pt-4">
                <legend class="text-sm font-semibold text-ink">Perks granted</legend>
                @foreach ($this->perkOptions() as $key => $label)
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" value="{{ $key }}" wire:model="perks"
                               class="h-4 w-4 rounded border-line text-accent focus:ring-accent">
                        <span class="text-ink">{{ $label }}</span>
                        <code class="text-xs text-ink-subtle">{{ $key }}</code>
                    </label>
                @endforeach
            </fieldset>

            <div class="flex items-center gap-3 border-t border-line pt-4">
                <x-ui.toggle name="isActive" wire:model="isActive" :checked="$isActive" label="Active (shown on the upgrade page)" />
            </div>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">Save tier</x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            </div>
        </form>
    @endif
</div>
