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

<a href="{{ route('notifications.index') }}" wire:poll.30s="refreshCount" title="Notifications"
   style="position:relative;text-decoration:none;color:inherit;font-size:1.15rem">
    🔔
    @if ($count > 0)
        <span aria-label="{{ $count }} unread notifications"
              style="position:absolute;top:-7px;right:-11px;background:#b00020;color:#fff;border-radius:10px;font-size:.7rem;line-height:1.4;padding:0 .35rem">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
