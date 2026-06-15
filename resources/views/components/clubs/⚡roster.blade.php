<?php

// SPDX-License-Identifier: Apache-2.0

use App\Clubs\ClubMembershipException;
use App\Clubs\ClubMembershipService;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Club roster + management (Phase 4 · M1.3). Visible to content-visible viewers; management controls
 * (approve/reject/promote/demote/remove/invite) only to owners + global admins (club.manage). Every action
 * re-asserts authorization and runs through ClubMembershipService, whose guards (sole-owner, the global-staff
 * ActorRank ceiling, single-use invites) are the real enforcement; the UI just surfaces refusals.
 */
new class extends Component
{
    #[Locked]
    public Club $club;

    public string $inviteEmail = '';

    public ?string $inviteLink = null;

    public ?string $error = null;

    public function mount(Club $club): void
    {
        $this->club = $club;
        // Content-visibility gate (no disclosure of a private club's roster).
        abort_unless($club->isContentVisibleTo(auth()->user()), 404);
    }

    public function canManage(): bool
    {
        return $this->club->isManageableBy(auth()->user());
    }

    public function approve(int $membershipId): void
    {
        $this->manage(fn (ClubMembershipService $s, User $a, ClubMembership $m) => $s->approve($this->club, $m, $a), $membershipId);
    }

    public function reject(int $membershipId): void
    {
        $this->manage(fn (ClubMembershipService $s, User $a, ClubMembership $m) => $s->reject($this->club, $m, $a), $membershipId);
    }

    public function setRole(int $membershipId, string $role): void
    {
        $this->manage(fn (ClubMembershipService $s, User $a, ClubMembership $m) => $s->changeRole($this->club, $m, $role, $a), $membershipId);
    }

    public function remove(int $membershipId): void
    {
        $this->manage(fn (ClubMembershipService $s, User $a, ClubMembership $m) => $s->removeMember($this->club, $m, $a), $membershipId);
    }

    public function createInvite(): void
    {
        $user = $this->ensureManager();
        $this->error = null;
        $this->inviteLink = null;

        $this->validate(['inviteEmail' => ['nullable', 'email', 'max:255']]);

        try {
            $invite = app(ClubMembershipService::class)->invite($this->club, $user, $this->inviteEmail ?: null);
            $this->inviteLink = route('clubs.invite.show', ['club' => $this->club, 'invitation' => $invite->token]);
            $this->reset('inviteEmail');
        } catch (ClubMembershipException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Resolve a membership scoped to THIS club, run the manager action, surface refusals. */
    private function manage(callable $action, int $membershipId): void
    {
        $actor = $this->ensureManager();
        $this->error = null;

        /** @var ClubMembership|null $membership */
        $membership = $this->club->memberships()->whereKey($membershipId)->first();
        if (! $membership instanceof ClubMembership) {
            $this->error = __('That member is no longer in this club.');

            return;
        }

        try {
            $action(app(ClubMembershipService::class), $actor, $membership);
            $this->club->refresh();
        } catch (ClubMembershipException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function ensureManager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->club->isManageableBy($user), 403);

        return $user;
    }

    /** @return \Illuminate\Support\Collection<int, ClubMembership> */
    public function activeMembers()
    {
        return $this->club->memberships()->with('user')->where('status', 'active')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 ELSE 2 END")
            ->orderBy('joined_at')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, ClubMembership> */
    public function pendingRequests()
    {
        return $this->canManage()
            ? $this->club->memberships()->with('user')->where('status', 'pending')->orderBy('created_at')->get()
            : collect();
    }
};
?>

<div class="space-y-5" dusk="club-roster">
    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    {{-- Pending requests (managers) --}}
    @if ($this->canManage() && $this->pendingRequests()->isNotEmpty())
        <x-ui.card>
            <div class="space-y-3">
                <h2 class="text-sm font-semibold text-ink">{{ __('Pending requests') }}</h2>
                <ul class="divide-y divide-line">
                    @foreach ($this->pendingRequests() as $m)
                        <li class="flex flex-wrap items-center gap-3 py-2 text-sm" wire:key="pending-{{ $m->id }}">
                            <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ $m->user?->display_name ?? $m->user?->username ?? $m->user?->name }}</span>
                            <x-ui.button size="sm" wire:click="approve({{ $m->id }})" dusk="approve-{{ $m->id }}">{{ __('Approve') }}</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="reject({{ $m->id }})">{{ __('Reject') }}</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card>
    @endif

    {{-- Invite (managers) --}}
    @if ($this->canManage())
        <x-ui.card>
            <div class="space-y-3">
                <h2 class="text-sm font-semibold text-ink">{{ __('Invite a member') }}</h2>
                <form wire:submit="createInvite" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-48">
                        <x-ui.input name="inviteEmail" type="email" :label="__('Bind to email (optional)')"
                            wire:model="inviteEmail" :hint="__('Leave blank for a link anyone can use once.')" dusk="invite-email" />
                    </div>
                    <x-ui.button type="submit" dusk="invite-create">{{ __('Create invite link') }}</x-ui.button>
                </form>
                @if ($inviteLink)
                    <div class="rounded-lg border border-line bg-surface-sunken p-3 text-sm" dusk="invite-link">
                        <p class="text-ink-subtle">{{ __('Share this single-use link (expires in :days days):', ['days' => \App\Clubs\ClubMembershipService::INVITE_TTL_DAYS]) }}</p>
                        <code class="mt-1 block break-all text-ink">{{ $inviteLink }}</code>
                    </div>
                @endif
            </div>
        </x-ui.card>
    @endif

    {{-- Active roster --}}
    <x-ui.card>
        <div class="space-y-3">
            <h2 class="text-sm font-semibold text-ink">{{ __('Members') }}</h2>
            <ul class="divide-y divide-line">
                @foreach ($this->activeMembers() as $m)
                    <li class="flex flex-wrap items-center gap-3 py-2 text-sm" wire:key="member-{{ $m->id }}">
                        <a href="{{ $m->user ? route('profiles.show', $m->user) : '#' }}" class="min-w-0 flex-1 truncate font-medium text-ink hover:text-accent">
                            {{ $m->user?->display_name ?? $m->user?->username ?? $m->user?->name }}
                        </a>
                        <x-ui.badge>{{ __(ucfirst((string) $m->role)) }}</x-ui.badge>
                        @if ($this->canManage())
                            <select class="rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink"
                                wire:change="setRole({{ $m->id }}, $event.target.value)" dusk="role-{{ $m->id }}">
                                <option value="member" @selected($m->role === 'member')>{{ __('Member') }}</option>
                                <option value="moderator" @selected($m->role === 'moderator')>{{ __('Moderator') }}</option>
                                <option value="owner" @selected($m->role === 'owner')>{{ __('Owner') }}</option>
                            </select>
                            <x-ui.button size="sm" variant="ghost" wire:click="remove({{ $m->id }})"
                                wire:confirm="{{ __('Remove this member?') }}" dusk="remove-{{ $m->id }}">{{ __('Remove') }}</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </x-ui.card>
</div>
