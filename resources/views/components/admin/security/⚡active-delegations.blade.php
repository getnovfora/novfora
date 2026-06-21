<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\DelegationException;
use App\Admin\DelegationService;
use App\Models\Club;
use App\Models\Delegation;
use App\Models\Forum;
use App\Models\Permission;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\RoleException;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * ACP v3 · v3-f — Active delegations (Security → Active delegations, ADR-0087). A co-owner grants an individual a
 * single capability for a bounded window (≤ 30 days) and sees / early-revokes the live delegations. All domain
 * logic + the apex fences live in {@see DelegationService}; this SFC is the form, the list, and the self-guard.
 * Authorization is re-asserted in mount() AND every action (Livewire actions bypass route middleware), mirroring
 * the v3-a ⚡co-owners pane exactly.
 */
new class extends Component
{
    public string $recipientRef = '';

    public string $permission = '';

    public string $scopeRef = 'global';

    public int $days = 7;

    public ?int $revokeId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureManager();
    }

    public function grant(): void
    {
        $this->ensureManager();

        $recipient = $this->resolveUser($this->recipientRef);
        if (! $recipient instanceof User) {
            $this->flash(__('admin.security.delegations.no_user', ['ref' => $this->recipientRef]), 'danger');

            return;
        }

        try {
            $scope = Scope::parse($this->scopeRef !== '' ? $this->scopeRef : 'global');
        } catch (InvalidArgumentException $e) {
            $this->flash($e->getMessage(), 'danger');

            return;
        }

        $days = max(1, min(DelegationService::MAX_DAYS, $this->days));

        try {
            $delegation = app(DelegationService::class)->grant(
                auth()->user(), $recipient, trim($this->permission), $scope, now()->addDays($days),
            );
            $this->reset('recipientRef', 'permission');
            $this->scopeRef = 'global';
            $this->days = 7;
            $this->flash(__('admin.security.delegations.granted', [
                'user' => $recipient->username,
                'expires' => $delegation->expires_at->diffForHumans(),
            ]), 'success');
        } catch (DelegationException|RoleException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function confirmRevoke(int $id): void
    {
        $this->ensureManager();
        $this->revokeId = $id;
        $this->message = null;
    }

    public function cancelRevoke(): void
    {
        $this->revokeId = null;
    }

    public function revoke(int $id): void
    {
        $this->ensureManager();
        $delegation = Delegation::findOrFail($id);

        app(DelegationService::class)->revoke(auth()->user(), $delegation);
        $this->revokeId = null;
        $this->flash(__('admin.security.delegations.revoked'), 'success');
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /** The live delegations (not revoked, not expired), newest first. @return \Illuminate\Support\Collection<int,Delegation> */
    public function delegations()
    {
        return Delegation::query()->live()->with(['delegator', 'recipient'])->orderByDesc('created_at')->get();
    }

    /** Catalog keys a co-owner may delegate: everything EXCEPT the Administration cluster. @return array<string,string> */
    public function permissionOptions(): array
    {
        return Permission::query()
            ->where(fn ($q) => $q->whereNull('group')->orWhere('group', '!=', 'Administration'))
            ->orderBy('group')->orderBy('label')
            ->pluck('label', 'key')->all();
    }

    /** key => label for the whole catalog (the list shows the human label, never the raw key). @return array<string,string> */
    public function permissionLabels(): array
    {
        return Permission::query()->pluck('label', 'key')->all();
    }

    /** A scope key ("global:*", "forum:2") → a human name for the list. */
    public function scopeName(string $type, ?int $id): string
    {
        if ($type === 'global') {
            return (string) __('admin.security.delegations.scope_global');
        }

        $name = match ($type) {
            'forum', 'category' => Forum::query()->whereKey($id)->value('title'),
            'club' => Club::query()->whereKey($id)->value('name'),
            'thread' => Topic::query()->whereKey($id)->value('title'),
            default => null,
        };

        return $name !== null && $name !== '' ? (string) $name : $type.' #'.$id;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    private function resolveUser(string $ref): ?User
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        return is_numeric($ref)
            ? User::find((int) $ref)
            : User::where('email', $ref)->orWhere('username', $ref)->first();
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    /** The Security-section gate: a 2FA-confirmed co-owner. Identical to the v3-a ⚡co-owners pane. */
    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('admin.security.access', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="acp-delegations">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="max-w-2xl text-sm text-ink-muted">
        {{ __('admin.security.delegations.intro') }}
    </p>

    {{-- Grant a new delegation --}}
    <x-ui.card>
        <form wire:submit="grant" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                <div class="space-y-1.5">
                    <label for="d-user" class="block text-sm font-medium text-ink">{{ __('admin.security.delegations.recipient') }}</label>
                    <input id="d-user" wire:model="recipientRef" required
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
                </div>
                <div class="space-y-1.5">
                    <label for="d-permission" class="block text-sm font-medium text-ink">{{ __('admin.security.delegations.capability') }}</label>
                    <select id="d-permission" wire:model="permission" required
                            class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line transition-colors focus:border-accent">
                        <option value="">{{ __('admin.security.delegations.choose_capability') }}</option>
                        @foreach ($this->permissionOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="d-scope" class="block text-sm font-medium text-ink">{{ __('admin.security.delegations.scope') }}</label>
                    <input id="d-scope" wire:model="scopeRef" placeholder="global | forum:2"
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border border-line transition-colors focus:border-accent">
                </div>
                <div class="space-y-1.5">
                    <label for="d-days" class="block text-sm font-medium text-ink">{{ __('admin.security.delegations.days') }}</label>
                    <input id="d-days" type="number" min="1" max="{{ \App\Admin\DelegationService::MAX_DAYS }}" wire:model="days"
                           class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line transition-colors focus:border-accent">
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="grant" dusk="acp-delegation-grant">
                    {{ __('admin.security.delegations.grant_action') }}
                </x-ui.button>
                <span class="text-xs text-ink-subtle">{{ __('admin.security.delegations.cap_hint', ['days' => \App\Admin\DelegationService::MAX_DAYS]) }}</span>
            </div>
        </form>
    </x-ui.card>

    {{-- Live delegations --}}
    @php($labels = $this->permissionLabels())
    <x-ui.card flush>
        <div class="border-b border-line bg-surface-sunken px-4 py-2.5 sm:px-5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            {{ __('admin.security.delegations.active_heading') }}
        </div>
        <ul class="divide-y divide-line">
            @forelse ($this->delegations() as $d)
                <li wire:key="delegation-{{ $d->id }}" class="px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 text-sm">
                            <p class="text-ink">
                                <strong class="font-semibold">{{ $d->recipient?->username ?? '—' }}</strong>
                                — {{ $labels[$d->permission_key] ?? $d->permission_key }}
                                <span class="text-ink-subtle">·</span>
                                {{ $this->scopeName($d->scope_type, $d->scope_id) }}
                            </p>
                            <p class="text-xs text-ink-subtle">
                                {{ __('admin.security.delegations.by_until', [
                                    'by' => $d->delegator?->username ?? '—',
                                    'expires' => $d->expires_at->diffForHumans(),
                                ]) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($revokeId === $d->id)
                                <span class="text-xs text-ink-muted">{{ __('admin.security.delegations.revoke_confirm') }}</span>
                                <x-ui.button type="button" variant="danger" size="sm"
                                             wire:click="revoke({{ $d->id }})" wire:loading.attr="disabled" wire:target="revoke({{ $d->id }})">
                                    {{ __('admin.security.delegations.confirm') }}
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelRevoke">
                                    {{ __('admin.security.delegations.cancel') }}
                                </x-ui.button>
                            @else
                                <x-ui.button type="button" variant="danger-ghost" size="sm"
                                             wire:click="confirmRevoke({{ $d->id }})" dusk="acp-delegation-revoke-{{ $d->id }}">
                                    {{ __('admin.security.delegations.revoke_action') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </div>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">{{ __('admin.security.delegations.empty') }}</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
