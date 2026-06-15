<?php

// SPDX-License-Identifier: Apache-2.0

use App\Clubs\ClubMembershipException;
use App\Clubs\ClubMembershipService;
use App\Models\Club;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The membership call-to-action on a club home (Phase 4 · M1.3): join a public club, request to join a
 * closed one, accept that you need an invite for a private one, or leave. Re-asserts auth in every action.
 */
new class extends Component
{
    #[Locked]
    public Club $club;

    public ?string $error = null;

    public function mount(Club $club): void
    {
        $this->club = $club;
    }

    public function join(): void
    {
        $this->run(fn (ClubMembershipService $svc, User $u) => $svc->join($this->club, $u));
    }

    public function requestJoin(): void
    {
        $this->run(fn (ClubMembershipService $svc, User $u) => $svc->requestToJoin($this->club, $u));
    }

    public function leave(): void
    {
        $this->run(fn (ClubMembershipService $svc, User $u) => $svc->leave($this->club, $u));
    }

    private function run(callable $action): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->error = null;

        try {
            $action(app(ClubMembershipService::class), $user);
            $this->club->refresh();
        } catch (ClubMembershipException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** active | pending | none — recomputed each render so the CTA reflects the latest roster state. */
    public function status(): string
    {
        $m = $this->club->membershipOf(auth()->user());

        return $m ? (string) $m->status : 'none';
    }
};
?>

<div class="space-y-2" dusk="club-join">
    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @guest
        <x-ui.button :href="route('login')">{{ __('Log in to join') }}</x-ui.button>
    @else
        @php($status = $this->status())
        @if ($status === 'active')
            <x-ui.button variant="ghost" wire:click="leave" dusk="club-leave"
                wire:confirm="{{ __('Leave this club?') }}">{{ __('Leave club') }}</x-ui.button>
        @elseif ($status === 'pending')
            <x-ui.badge>{{ __('Request pending approval') }}</x-ui.badge>
        @elseif ($club->joinPolicy() === 'open')
            <x-ui.button wire:click="join" dusk="club-join-btn">{{ __('Join club') }}</x-ui.button>
        @elseif ($club->joinPolicy() === 'request')
            <x-ui.button wire:click="requestJoin" dusk="club-request-btn">{{ __('Request to join') }}</x-ui.button>
        @else
            <x-ui.badge>{{ __('Invite only') }}</x-ui.badge>
        @endif
    @endguest
</div>
