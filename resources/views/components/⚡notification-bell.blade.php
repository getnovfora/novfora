<?php
// SPDX-License-Identifier: Apache-2.0
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Livewire\Component;

new class extends Component
{
    public int $count = 0;

    public bool $realtime = false;

    public ?int $userId = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->userId = $user?->getKey();
        // Phase 4 · M4.2: when a realtime broadcaster is configured, the bell ALSO subscribes to the user's
        // private notifications channel for instant updates. The wire:poll below always stays as the baseline
        // fallback — the subscription is purely additive and inert if window.Echo isn't present.
        $this->realtime = app(ServiceTier::class)->isEnhanced(Capability::Broadcast);
        $this->refreshCount();
    }

    // Baseline near-real-time: poll the unread count. On the enhanced tier the Echo subscription below pushes
    // the same refresh instantly; the poll remains as the always-correct backstop.
    public function refreshCount(): void
    {
        $user = auth()->user();
        $this->count = $user ? $user->unreadNotifications()->count() : 0;
    }
};
?>

<a href="{{ route('notifications.index') }}" wire:poll.30s="refreshCount"
   @if ($realtime && $userId)
       x-data
       x-init="
           if (window.Echo) {
               window.Echo.private('notifications.{{ $userId }}')
                   .listen('.notification.received', () => $wire.refreshCount());
           }
       "
   @endif
   title="Notifications" aria-label="Notifications{{ $count > 0 ? ' ('.$count.' unread)' : '' }}"
   class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
    <x-ui.icon name="bell" />
    @if ($count > 0)
        <span aria-hidden="true"
              class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 inline-flex items-center justify-center rounded-full bg-danger-strong text-white text-[0.625rem] font-semibold leading-none nums">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
