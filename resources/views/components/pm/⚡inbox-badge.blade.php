<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    // Baseline near-real-time: poll the unread conversation count (Reverb push is Phase 4).
    public function refreshCount(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            $this->count = 0;

            return;
        }

        // Unread = active conversations where last_read_at IS NULL or < last_message_at.
        $this->count = (int) $user->conversations()
            ->wherePivotNull('left_at')
            ->where(function ($q) {
                $q->whereNull('conversation_user.last_read_at')
                    ->orWhereColumn('conversation_user.last_read_at', '<', 'conversations.last_message_at');
            })
            ->count();
    }
};
?>

<a href="{{ route('pm.inbox') }}" wire:poll.60s="refreshCount" dusk="pm-inbox-badge"
   title="Messages" aria-label="Messages{{ $count > 0 ? ' ('.$count.' unread)' : '' }}"
   class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
    <x-ui.icon name="mail" />
    @if ($count > 0)
        <span aria-hidden="true"
              class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 inline-flex items-center justify-center rounded-full bg-danger-strong text-white text-[0.625rem] font-semibold leading-none nums">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
