<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\SubscriptionService;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Follow/unfollow a topic (notify on new replies) or a forum (notify on new topics) — M2, ADR-0097. Generic
 * over the target via a short KIND string the service maps to a model (the view never names a class). Following
 * is ungated participation (no ACL key); the fan-out's per-recipient visibility gate is the privacy fence.
 * Authorisation (signed in) is re-asserted in the action since Livewire actions are public.
 */
new class extends Component
{
    #[Locked]
    public string $kind; // 'topic' | 'forum'

    #[Locked]
    public int $targetId;

    public bool $subscribed = false;

    public function mount(SubscriptionService $service, string $kind, int $targetId): void
    {
        $this->kind = $kind;
        $this->targetId = $targetId;

        $user = auth()->user();
        if ($user instanceof User) {
            $target = $service->resolve($kind, $targetId);
            $this->subscribed = $target !== null && $service->isSubscribed($user, $target);
        }
    }

    public function toggle(SubscriptionService $service): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $target = $service->resolve($this->kind, $this->targetId);
        abort_unless($target !== null, 404);

        $this->subscribed = $service->toggle($user, $target);
    }
};
?>

<div dusk="subscribe-{{ $kind }}-{{ $targetId }}">
    @auth
        <button type="button" wire:click="toggle" wire:loading.attr="disabled"
            @class([
                'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium transition',
                'border-accent bg-accent-soft text-accent' => $subscribed,
                'border-line text-ink-muted hover:border-accent hover:text-accent' => ! $subscribed,
            ])
            aria-pressed="{{ $subscribed ? 'true' : 'false' }}"
            title="{{ $subscribed
                ? 'Following — you’ll be notified of new activity'
                : ($kind === 'forum' ? 'Follow this forum — get notified of new topics' : 'Follow this topic — get notified of new replies') }}">
            <x-ui.icon name="bell" class="h-3.5 w-3.5" />
            <span>{{ $subscribed ? 'Following' : 'Follow' }}</span>
        </button>
    @endauth
</div>
