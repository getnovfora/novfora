<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        // Own-inbox only — never accept a user-id param (own-inbox rule).
        abort_unless(auth()->user() instanceof User, 403);
    }

    /**
     * Active conversations for the authenticated user, ordered by most recent message. Eager-loads to stay
     * within the ≤15-query budget: conversations + pivot, the participant rows + their users (for the "other
     * participants" line), and the latest message via a hasOne subquery (lastMessage — avoids the eager-load
     * limit(1) pitfall, which would cap the whole result set to one row rather than one per conversation).
     *
     * @return Collection<int, Conversation>
     */
    public function conversations(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->conversations()
            ->wherePivotNull('left_at')
            ->with(['participantRows.user', 'lastMessage'])
            ->orderByDesc('last_message_at')
            ->get();
    }
};
?>

<div dusk="pm-inbox">
    @php
        $conversations = $this->conversations();
    @endphp

    @if ($conversations->isEmpty())
        <x-ui.empty title="No conversations yet">
            You have no private messages. <a href="{{ route('pm.create') }}" class="text-accent hover:underline">Start a new conversation.</a>
        </x-ui.empty>
    @else
        <ul class="divide-y divide-line">
            @foreach ($conversations as $conversation)
                @php
                    /** @var \App\Models\Conversation $conversation */
                    $pivot = $conversation->pivot;
                    $lastRead = $pivot?->last_read_at;
                    $unread = $lastRead === null || $lastRead < $conversation->last_message_at;

                    // Other participants (exclude self).
                    $others = $conversation->participantRows
                        ->filter(fn ($row) => (int) $row->user_id !== (int) auth()->id() && $row->left_at === null)
                        ->map(fn ($row) => $row->user)
                        ->filter();

                    // Latest message snippet / subject.
                    $latestMessage = $conversation->lastMessage;
                    $snippet = $conversation->subject
                        ?? ($latestMessage ? \Illuminate\Support\Str::limit((string) $latestMessage->body_text, 80) : '(no messages)');
                @endphp
                <li dusk="pm-conversation-row-{{ $conversation->id }}">
                    <a href="{{ route('pm.show', $conversation->id) }}"
                       class="flex items-start gap-3 px-4 py-3 hover:bg-surface-sunken transition-colors {{ $unread ? 'bg-accent-soft/30' : '' }}">

                        {{-- Avatars: stack up to 2 others --}}
                        <div class="relative shrink-0 mt-0.5">
                            @if ($others->count() === 1)
                                <x-ui.avatar :user="$others->first()" size="sm" />
                            @elseif ($others->count() >= 2)
                                <x-ui.avatar :user="$others->first()" size="xs" class="absolute top-0 left-0 ring-2 ring-surface-raised" />
                                <x-ui.avatar :user="$others->get(1)" size="xs" class="relative mt-3 ml-3 ring-2 ring-surface-raised" />
                            @else
                                {{-- Conversation with only self (edge case) --}}
                                <x-ui.avatar :user="auth()->user()" size="sm" />
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-ink truncate">
                                    @if ($others->isNotEmpty())
                                        @foreach ($others->take(3) as $i => $other)
                                            @if ($i > 0), @endif
                                            <x-ui.user-name :user="$other" />
                                        @endforeach
                                        @if ($others->count() > 3)
                                            <span class="text-ink-subtle"> +{{ $others->count() - 3 }} more</span>
                                        @endif
                                    @else
                                        <span class="text-ink-subtle">(you)</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-xs text-ink-subtle nums">
                                    {{ $conversation->last_message_at?->diffForHumans() ?? '' }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-sm text-ink-muted truncate">{{ $snippet }}</p>
                        </div>

                        @if ($unread)
                            <span class="shrink-0 mt-1.5 h-2 w-2 rounded-full bg-accent" aria-label="Unread"></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
