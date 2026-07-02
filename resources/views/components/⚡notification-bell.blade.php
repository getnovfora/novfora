<?php
// SPDX-License-Identifier: Apache-2.0
use App\Notifications\NotificationVisibility;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;

new class extends Component
{
    public int $count = 0;

    public bool $realtime = false;

    public ?int $userId = null;

    /** Lazy-loaded recent notifications for the dropdown — populated on open only (never on the header
     *  render), so the bell adds NO query to the per-page render (query-budget discipline). Reloaded on
     *  EVERY open (BETA-1/NOV-85): the old first-open latch let the list disagree with the polling badge
     *  for the rest of the page's life. @var array<int,array<string,mixed>> */
    public array $recent = [];

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

    /** Load the latest notifications for the dropdown, re-gated for club visibility (no private-club leak).
     *  Also refreshes the badge so list + count update atomically in one round-trip. */
    public function loadRecent(): void
    {
        $user = auth()->user();
        if (! $user) {
            $this->recent = [];

            return;
        }

        $this->recent = $user->notifications()->latest()->limit(10)->get()
            ->filter(fn (DatabaseNotification $n): bool => NotificationVisibility::visibleTo($n, $user))
            ->take(7)
            ->map(fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'unread' => $n->read_at === null,
                'event' => (string) ($n->data['event'] ?? ''),
                'actor' => (string) ($n->data['actors'][0]['username'] ?? 'Someone'),
                'topic' => $n->data['topic_title'] ?? null,
                'when' => $n->created_at?->diffForHumans() ?? '',
            ])->values()->all();
        $this->refreshCount();
    }

    public function markAllRead(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->refreshCount();
        $this->loadRecent();
    }
};
?>

{{-- Notification bell + inline dropdown (M4 polish). The badge count polls on the baseline tier (Echo pushes
     it instantly on the enhanced tier); the dropdown lazy-loads the latest few notifications on first open,
     re-gated through NotificationVisibility so a now-private club topic's title never leaks. --}}
<div class="relative"
     x-data="{ open: false }"
     @keydown.escape="open = false"
     @click.outside="open = false"
     wire:poll.30s="refreshCount"
     @if ($realtime && $userId)
         x-init="
             if (window.Echo) {
                 window.Echo.private('notifications.{{ $userId }}')
                     .listen('.notification.received', () => $wire.refreshCount());
             }
         "
     @endif
>
    <button type="button"
            @click="open = ! open; if (open) { $wire.loadRecent() }"
            :aria-expanded="open.toString()" aria-haspopup="true"
            title="Notifications" aria-label="Notifications{{ $count > 0 ? ' ('.$count.' unread)' : '' }}"
            class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
        <x-ui.icon name="bell" />
        @if ($count > 0)
            <span aria-hidden="true"
                  class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 inline-flex items-center justify-center rounded-full bg-danger-strong text-white text-[0.625rem] font-semibold leading-none nums">{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute right-0 z-40 mt-2 w-80 max-w-[90vw] overflow-hidden rounded-lg border border-line bg-surface-raised shadow-md"
         role="region" aria-label="Recent notifications">
        <div class="flex items-center justify-between border-b border-line px-3 py-2">
            <p class="text-sm font-semibold text-ink">Notifications</p>
            @if ($count > 0)
                <button type="button" wire:click="markAllRead"
                        class="text-xs text-ink-muted hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded">Mark all read</button>
            @endif
        </div>

        <div wire:loading.delay wire:target="loadRecent" class="px-3 py-6 text-center text-sm text-ink-subtle">Loading…</div>

        <ul wire:loading.remove wire:target="loadRecent" class="max-h-96 divide-y divide-line overflow-y-auto">
            @forelse ($recent as $item)
                <li>
                    {{-- Click-through: opening a notification marks it read (notifications.open, BETA-1). --}}
                    <a href="{{ route('notifications.open', $item['id']) }}" @class([
                        'flex items-start gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-surface-sunken',
                        'bg-accent-soft/40' => $item['unread'],
                    ])>
                        <span @class([
                            'mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                            'bg-accent-soft text-accent-soft-ink' => $item['unread'],
                            'bg-surface-sunken text-ink-subtle' => ! $item['unread'],
                        ])>
                            @switch($item['event'])
                                @case('reply') <x-ui.icon name="reply" class="h-3.5 w-3.5" /> @break
                                @case('mention') <x-ui.icon name="message" class="h-3.5 w-3.5" /> @break
                                @case('pm.received') <x-ui.icon name="mail" class="h-3.5 w-3.5" /> @break
                                @case('follow') <x-ui.icon name="user" class="h-3.5 w-3.5" /> @break
                                @case('moderation') <x-ui.icon name="shield" class="h-3.5 w-3.5" /> @break
                                @default <x-ui.icon name="bell" class="h-3.5 w-3.5" />
                            @endswitch
                        </span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block', 'font-semibold text-ink' => $item['unread'], 'text-ink-muted' => ! $item['unread']])>
                                @switch($item['event'])
                                    @case('reply') {{ $item['actor'] }} replied in “{{ $item['topic'] ?? 'a thread' }}” @break
                                    @case('mention') {{ $item['actor'] }} mentioned you in “{{ $item['topic'] ?? 'a discussion' }}” @break
                                    @case('reaction') {{ $item['actor'] }} reacted to your post @break
                                    @case('pm.received') {{ $item['actor'] }} sent you a message @break
                                    @case('follow') {{ $item['actor'] }} started following you @break
                                    @case('moderation') You received a moderation notice @break
                                    @default New notification
                                @endswitch
                            </span>
                            <span class="mt-0.5 block text-xs text-ink-subtle nums">{{ $item['when'] }}</span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-sm text-ink-subtle">You’re all caught up.</li>
            @endforelse
        </ul>

        <a href="{{ route('notifications.index') }}"
           class="block border-t border-line px-3 py-2 text-center text-sm font-medium text-accent hover:bg-surface-sunken">See all notifications</a>
    </div>
</div>
