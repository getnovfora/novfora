<?php
// SPDX-License-Identifier: Apache-2.0
use App\Membership\Payments\ManualPaymentProvider;
use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * Admin → Memberships (Phase 4 · M5.2). The offline/MANUAL grant surface — the only live-granting path in this
 * build. An admin records that a member has paid and grants them a tier (optionally with an expiry); the active
 * grants are listed with a revoke. All capability changes flow through MembershipService → the engine.
 * Authorization is re-asserted in mount() AND every action (Livewire actions bypass route middleware).
 */
new class extends Component
{
    public string $username = '';

    public ?int $tierId = null;

    public int $days = 0; // 0 = no expiry

    public ?string $saved = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->tierId = MembershipTier::query()->where('is_active', true)->orderBy('sort')->value('id');
    }

    public function grant(ManualPaymentProvider $manual): void
    {
        $this->ensureAdmin();
        $this->saved = $this->error = null;

        $data = $this->validate([
            'username' => ['required', 'string'],
            'tierId' => ['required', 'integer'],
            'days' => ['integer', 'min:0', 'max:3660'],
        ]);

        $user = User::query()
            ->where('username', $data['username'])
            ->orWhere('email', $data['username'])
            ->first();
        $tier = MembershipTier::query()->where('is_active', true)->find($data['tierId']);

        if (! $user instanceof User) {
            $this->error = 'No member found with that username or email.';

            return;
        }
        if (! $tier instanceof MembershipTier) {
            $this->error = 'Choose an active tier.';

            return;
        }

        $manual->grant($user, $tier, $this->days > 0 ? now()->addDays($this->days) : null);
        $this->reset(['username', 'days']);
        $this->saved = "Granted {$tier->name} to {$user->username}.";
    }

    public function revoke(int $subscriptionId, ManualPaymentProvider $manual): void
    {
        $this->ensureAdmin();
        $subscription = MemberSubscription::find($subscriptionId);
        if ($subscription instanceof MemberSubscription) {
            $manual->revoke($subscription);
            $this->saved = 'Membership revoked.';
        }
    }

    /** @return \Illuminate\Support\Collection<int,MembershipTier> */
    public function tiers()
    {
        return MembershipTier::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }

    /** @return \Illuminate\Support\Collection<int,MemberSubscription> */
    public function activeGrants()
    {
        return MemberSubscription::query()
            ->where('status', 'active')
            ->with(['user', 'tier'])
            ->latest('started_at')
            ->limit(50)
            ->get();
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
    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="grant" class="space-y-4">
            <h2 class="text-sm font-semibold text-ink">Grant a membership</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input label="Member (username or email)" name="username" wire:model="username" />
                <x-ui.select label="Tier" name="tierId" wire:model="tierId">
                    @foreach ($this->tiers() as $tier)
                        <option value="{{ $tier->id }}">{{ $tier->name }} ({{ $tier->priceLabel() }})</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Expires in (days)" name="days" type="number" wire:model="days" hint="0 = no expiry" />
            </div>
            <x-ui.button type="submit">Grant membership</x-ui.button>
        </form>
    </x-ui.card>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-ink">Active memberships</h2>
        @forelse ($this->activeGrants() as $grant)
            <x-ui.card>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-ink">{{ $grant->user?->username ?? 'Unknown' }} — {{ $grant->tier?->name ?? 'Unknown tier' }}</p>
                        <p class="text-xs text-ink-subtle">
                            via {{ $grant->provider }}@if ($grant->expires_at) · expires {{ $grant->expires_at->toFormattedDateString() }} @else · no expiry @endif
                        </p>
                    </div>
                    <x-ui.button variant="danger-ghost" wire:click="revoke({{ $grant->id }})">Revoke</x-ui.button>
                </div>
            </x-ui.card>
        @empty
            <p class="text-sm text-ink-subtle">No active memberships.</p>
        @endforelse
    </div>
</div>
