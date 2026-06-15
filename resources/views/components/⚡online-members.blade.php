<?php
// SPDX-License-Identifier: Apache-2.0
use App\Presence\OnlineMembers;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Livewire\Component;

/**
 * Live "who's online" widget (Phase 4 · M4.3). BASELINE-SAFE: it polls the OnlineMembers service every 60s
 * (wire:poll) so it works on a cron-only host with no realtime daemon. On the ENHANCED tier it ALSO joins the
 * global presence channel via Echo for instant updates — purely additive and inert if window.Echo is absent.
 * Only opted-in members are listed (the OnlineMembers service enforces the privacy rule).
 */
new class extends Component
{
    /** @var array<int, array{id:int, username:string}> */
    public array $members = [];

    public int $total = 0;

    public bool $realtime = false;

    public function mount(): void
    {
        $this->realtime = app(ServiceTier::class)->isEnhanced(Capability::Broadcast);
        $this->refresh();
    }

    public function refresh(): void
    {
        $service = app(OnlineMembers::class);
        $this->members = $service->recent(30)
            ->map(fn ($u) => ['id' => (int) $u->id, 'username' => (string) $u->username])
            ->all();
        $this->total = $service->count();
    }
};
?>

<div class="rounded-lg border border-line bg-surface-raised p-4"
     wire:poll.60s="refresh"
     @if ($realtime)
         x-data="{
             init() {
                 if (window.Echo) {
                     window.Echo.join('online')
                         .here(() => $wire.refresh())
                         .joining(() => $wire.refresh())
                         .leaving(() => $wire.refresh());
                 }
             }
         }"
     @endif>
    <h3 class="mb-2 text-sm font-semibold text-ink">
        {{ __("Who's online") }} <span class="text-ink-subtle nums">({{ $total }})</span>
    </h3>

    @if (count($members) === 0)
        <p class="text-sm text-ink-subtle">{{ __('No one online right now.') }}</p>
    @else
        <ul class="flex flex-wrap gap-x-2 gap-y-1 text-sm">
            @foreach ($members as $member)
                <li>
                    <a class="text-ink hover:text-accent" href="{{ route('profiles.show', $member['id']) }}">{{ $member['username'] }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
