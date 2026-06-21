<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\AdminCoOwnerException;
use App\Admin\AdminCoOwnerService;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * ACP v3 · v3-a — Co-owners pane (Security → Co-owners). Lists the current co-owners and lets any co-owner
 * appoint or remove others, bounded by the last-owner guard in AdminCoOwnerService. Authorization is
 * re-asserted in mount() AND every action (Livewire actions bypass route middleware).
 */
new class extends Component
{
    public ?int $removeId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureManager();
    }

    public function appoint(int $userId): void
    {
        $this->ensureManager();
        $target = User::findOrFail($userId);

        try {
            app(AdminCoOwnerService::class)->grant(auth()->user(), $target);
            $this->flash("“{$target->username}” is now a co-owner.", 'success');
        } catch (AdminCoOwnerException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    public function confirmRemove(int $userId): void
    {
        $this->ensureManager();
        $this->removeId = $userId;
        $this->message = null;
    }

    public function cancelRemove(): void
    {
        $this->removeId = null;
    }

    public function remove(int $userId): void
    {
        $this->ensureManager();
        $target = User::findOrFail($userId);

        try {
            app(AdminCoOwnerService::class)->revoke(auth()->user(), $target);
            $this->removeId = null;
            $this->flash("“{$target->username}” is no longer a co-owner.", 'success');
        } catch (AdminCoOwnerException $e) {
            $this->flash($e->getMessage(), 'danger');
        }
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────────────────

    /** @return Collection<int,User> */
    public function coOwners()
    {
        $ids = app(AdminCoOwnerService::class)->coOwnerIds();

        return User::query()->whereIn('id', $ids)->orderBy('username')->get();
    }

    /** Admins who are not yet co-owners — candidates for promotion. @return \Illuminate\Database\Eloquent\Collection<int,User> */
    public function candidates()
    {
        $ids = app(AdminCoOwnerService::class)->coOwnerIds();

        return User::query()
            ->whereHas('groups', fn ($q) => $q->where('slug', 'admins'))
            ->whereNotIn('id', $ids)
            ->orderBy('username')
            ->get();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        abort_unless($user->canDo('admin.security.access', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="acp-co-owners">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <p class="max-w-2xl text-sm text-ink-muted">
        <strong>Co-owners</strong> are the top administrator tier. Any co-owner can appoint or remove another;
        there is no single "root" owner and no transfer protocol — the forum can have multiple co-owners
        simultaneously. The <strong>last co-owner cannot be removed</strong>: appoint a replacement first.
    </p>

    {{-- Current co-owners --}}
    <x-ui.card flush>
        <div class="border-b border-line bg-surface-sunken px-4 py-2.5 sm:px-5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            Current co-owners
        </div>
        <ul class="divide-y divide-line">
            @forelse ($this->coOwners() as $owner)
                <li wire:key="co-owner-{{ $owner->id }}" class="px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-ui.avatar :user="$owner" size="sm" class="shrink-0" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">
                                    <x-ui.user-name :user="$owner" />
                                </p>
                                <p class="text-xs text-ink-subtle">{{ $owner->username }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($removeId === $owner->id)
                                <span class="text-xs text-ink-muted">Remove co-owner?</span>
                                <x-ui.button type="button" variant="danger" size="sm"
                                             wire:click="remove({{ $owner->id }})"
                                             wire:loading.attr="disabled"
                                             wire:target="remove({{ $owner->id }})">
                                    Confirm
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="sm"
                                             wire:click="cancelRemove">
                                    Cancel
                                </x-ui.button>
                            @else
                                <x-ui.button type="button" variant="danger-ghost" size="sm"
                                             wire:click="confirmRemove({{ $owner->id }})"
                                             dusk="acp-co-owner-remove-{{ $owner->id }}">
                                    Remove
                                </x-ui.button>
                            @endif
                        </div>
                    </div>

                    @if ($removeId === $owner->id)
                        <x-ui.alert variant="warn" class="mt-3">
                            Removing a co-owner strips their access to the Security section. The last co-owner
                            cannot be removed — this action will fail if they are the only one left.
                        </x-ui.alert>
                    @endif
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">
                    No co-owners found. This should not happen — the installer always crowns the first admin as co-owner.
                </li>
            @endforelse
        </ul>
    </x-ui.card>

    {{-- Appoint a new co-owner from candidate admins --}}
    <x-ui.card flush>
        <div class="border-b border-line bg-surface-sunken px-4 py-2.5 sm:px-5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            Appoint a co-owner
        </div>
        @php($candidates = $this->candidates())
        @if ($candidates->isEmpty())
            <p class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">
                All administrators are already co-owners.
                <a href="{{ route('admin.members.groups') }}" class="text-accent hover:underline" dusk="co-owners-groups-link">Add an administrator via Groups</a> first.
            </p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($candidates as $candidate)
                    <li wire:key="candidate-{{ $candidate->id }}"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-ui.avatar :user="$candidate" size="sm" class="shrink-0" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">
                                    <x-ui.user-name :user="$candidate" />
                                </p>
                                <p class="text-xs text-ink-subtle">{{ $candidate->username }}</p>
                            </div>
                        </div>
                        <x-ui.button type="button" size="sm"
                                     wire:click="appoint({{ $candidate->id }})"
                                     wire:loading.attr="disabled"
                                     wire:target="appoint({{ $candidate->id }})"
                                     dusk="acp-co-owner-appoint-{{ $candidate->id }}">
                            <span wire:loading.remove wire:target="appoint({{ $candidate->id }})">Appoint</span>
                            <span wire:loading wire:target="appoint({{ $candidate->id }})">Appointing…</span>
                        </x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
