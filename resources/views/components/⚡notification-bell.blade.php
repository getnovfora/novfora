<?php
// SPDX-License-Identifier: Apache-2.0
use Livewire\Component;

new class extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    // Baseline near-real-time: poll the unread count (Reverb push is Phase 4).
    public function refreshCount(): void
    {
        $user = auth()->user();
        $this->count = $user ? $user->unreadNotifications()->count() : 0;
    }
};
?>

<a href="{{ route('notifications.index') }}" wire:poll.30s="refreshCount"
   title="Notifications" aria-label="Notifications{{ $count > 0 ? ' ('.$count.' unread)' : '' }}"
   class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
    <x-ui.icon name="bell" />
    @if ($count > 0)
        <span aria-hidden="true"
              class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 inline-flex items-center justify-center rounded-full bg-danger-strong text-white text-[0.625rem] font-semibold leading-none nums">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
