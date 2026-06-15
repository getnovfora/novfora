<?php
// SPDX-License-Identifier: Apache-2.0

use App\Forum\PostScheduler;
use App\Models\ScheduledPost;
use App\Models\User;
use Livewire\Component;

/**
 * The signed-in user's pending scheduled replies (member tool 2.4), with one-click cancel. Reads/writes only
 * the authenticated user's own rows; cancel is a no-op once a reply has published.
 */
new class extends Component
{
    public ?string $message = null;

    /** @return \Illuminate\Support\Collection<int,ScheduledPost> */
    public function rows()
    {
        return app(PostScheduler::class)->pendingFor($this->me());
    }

    public function cancel(int $id): void
    {
        $scheduled = ScheduledPost::query()->where('user_id', $this->me()->getKey())->whereKey($id)->first();
        if ($scheduled instanceof ScheduledPost && app(PostScheduler::class)->cancel($scheduled)) {
            $this->message = 'Scheduled reply cancelled.';
        }
    }

    private function me(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

<div class="space-y-4" dusk="scheduled-posts">
    @if ($message)
        <x-ui.alert variant="success">{{ $message }}</x-ui.alert>
    @endif

    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->rows() as $scheduled)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0 flex-1">
                        <a href="{{ $scheduled->topic ? route('topics.show', $scheduled->topic) : '#' }}"
                           class="block truncate font-medium text-ink hover:text-accent">
                            {{ $scheduled->topic?->title ?? '(deleted topic)' }}
                        </a>
                        <p class="text-xs text-ink-subtle">
                            Publishes {{ $scheduled->publish_at?->diffForHumans() }} · {{ $scheduled->publish_at?->isoFormat('lll') }}
                        </p>
                    </div>
                    <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="cancel({{ $scheduled->id }})"
                                 dusk="cancel-scheduled-{{ $scheduled->id }}">Cancel</x-ui.button>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">You have no scheduled replies.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
