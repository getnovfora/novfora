<?php

// SPDX-License-Identifier: Apache-2.0

use App\Admin\GroupException;
use App\Groups\GroupJoinGate;
use App\Groups\GroupMembershipService;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The membership call-to-action on the public Groups directory (ACP v3 · v3-e, ADR-0083): join an open
 * group, request to join a request-model group, or leave. Re-asserts auth in every action.
 *
 * PRIVACY: only open/request groups ever render a join control. Roster is never shown — only the COUNT
 * (rendered by the parent view). GroupJoinGate blocks banned/suspended/unverified accounts from joining.
 */
new class extends Component
{
    #[Locked]
    public Group $group;

    public ?string $error = null;

    public function mount(Group $group): void
    {
        $this->group = $group;
    }

    public function join(): void
    {
        $this->run(fn (GroupMembershipService $svc, User $u) => $svc->joinOpen($this->group, $u));
    }

    public function request(): void
    {
        $this->run(fn (GroupMembershipService $svc, User $u) => $svc->requestToJoin($this->group, $u));
    }

    public function leave(): void
    {
        $this->run(fn (GroupMembershipService $svc, User $u) => $svc->leave($this->group, $u));
    }

    private function run(callable $action): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->error = null;

        try {
            $action(app(GroupMembershipService::class), $user);
            $this->group->refresh();
        } catch (GroupException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Whether the current authed user is already a member of this group. */
    public function isMember(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return $this->group->users()->whereKey($user->getKey())->exists();
    }

    /** Whether the current authed user has a pending join request for this group. */
    public function hasPendingRequest(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return GroupJoinRequest::where('group_id', $this->group->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', GroupJoinRequest::STATUS_PENDING)
            ->exists();
    }
};
?>

<div class="space-y-2">
    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @guest
        @if ($group->allowsOpenJoin() || $group->acceptsJoinRequests())
            <x-ui.button variant="ghost" :href="route('login')">{{ __('Sign in to join') }}</x-ui.button>
        @endif
    @else
        @php
            $blocked = GroupJoinGate::reasonBlocked(auth()->user());
            $member  = $this->isMember();
        @endphp

        @if ($member)
            <x-ui.badge>{{ __('Member') }}</x-ui.badge>
            @if ($group->allowsOpenJoin() || $group->acceptsJoinRequests())
                <x-ui.button variant="ghost" wire:click="leave"
                    wire:confirm="{{ __('Leave this group?') }}">{{ __('Leave') }}</x-ui.button>
            @endif
        @elseif ($blocked !== null)
            <span class="text-xs text-ink-subtle">{{ $blocked }}</span>
        @elseif ($group->allowsOpenJoin())
            <x-ui.button wire:click="join" dusk="join-{{ $this->group->id }}">{{ __('Join') }}</x-ui.button>
        @elseif ($group->acceptsJoinRequests())
            @if ($this->hasPendingRequest())
                <x-ui.badge>{{ __('Requested') }}</x-ui.badge>
            @else
                <x-ui.button wire:click="request" dusk="join-{{ $this->group->id }}">{{ __('Request to join') }}</x-ui.button>
            @endif
        @endif
    @endguest
</div>
