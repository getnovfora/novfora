<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\PollService;
use App\Forum\PollVoteException;
use App\Models\Poll;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $pollId;

    #[Locked]
    public int $topicId;

    #[Locked]
    public bool $canVote = false;

    /** @var array<string,mixed> primitives display data from PollService::displayData() */
    public array $poll = [];

    /** @var list<int> the viewer's voted option ids */
    public array $voted = [];

    public ?int $choice = null;   // single-choice selection

    public array $selected = [];  // multi-choice selection

    public bool $showResults = false;

    public function mount(int $pollId, int $topicId, array $poll = [], array $voted = [], bool $canVote = false): void
    {
        $this->pollId = $pollId;
        $this->topicId = $topicId;
        $this->poll = $poll;
        $this->voted = $voted;
        $this->canVote = $canVote;
        $this->choice = $voted[0] ?? null;
        $this->selected = $voted;
        $this->showResults = $voted !== [] || $this->closed();
    }

    public function vote(PollService $service): void
    {
        $user = auth()->user();
        $scope = Scope::thread($this->topicId);

        // Re-assert at the action (Livewire actions are public): must be able to view the forum AND vote.
        abort_unless(
            $user instanceof User && $user->canDo('forum.view', $scope) && $user->canDo('poll.vote', $scope),
            403,
        );

        $ids = ($this->poll['is_multiple'] ?? false)
            ? array_map('intval', $this->selected)
            : ($this->choice !== null ? [(int) $this->choice] : []);

        $poll = Poll::findOrFail($this->pollId);
        // Defense-in-depth: the (pollId, topicId) pair is server-supplied by the parent and #[Locked], so a
        // mismatch shouldn't be reachable — but pin it, so the permission scope (built from topicId) can never
        // be checked against a different topic's poll regardless of future mounting changes.
        abort_unless((int) $poll->topic_id === $this->topicId, 403);

        try {
            $service->vote($user, $poll, $ids);
        } catch (PollVoteException $e) {
            $this->addError('poll', $e->getMessage());

            return;
        }

        $this->poll = $service->displayData($poll->fresh());
        $this->voted = $service->votedOptionIds($user, $poll);
        $this->choice = $this->voted[0] ?? null;
        $this->selected = $this->voted;
        $this->showResults = true;
    }

    public function toggleResults(): void
    {
        $this->showResults = ! $this->showResults;
    }

    public function closed(): bool
    {
        return ($this->poll['is_closed'] ?? false)
            || (($this->poll['closes_at'] ?? null) !== null && Carbon::parse($this->poll['closes_at'])->isPast());
    }
};
?>

<x-ui.card dusk="poll-{{ $pollId }}" class="space-y-3">
    @php($closed = $this->closed())
    @php($total = (int) ($poll['total_voters'] ?? 0))
    <div class="flex items-center justify-between gap-2">
        <h3 class="font-semibold text-ink">{{ $poll['question'] ?? '' }}</h3>
        @if ($closed)
            <x-ui.badge variant="warn">Closed</x-ui.badge>
        @endif
    </div>

    @if (! $showResults && $canVote && ! $closed)
        <form wire:submit="vote" class="space-y-2" dusk="poll-vote-form">
            @foreach ($poll['options'] ?? [] as $opt)
                <label class="flex items-center gap-2 text-sm text-ink">
                    @if ($poll['is_multiple'] ?? false)
                        <input type="checkbox" value="{{ $opt['id'] }}" wire:model="selected" dusk="poll-opt-{{ $opt['id'] }}">
                    @else
                        <input type="radio" name="poll-choice" value="{{ $opt['id'] }}" wire:model="choice" dusk="poll-opt-{{ $opt['id'] }}">
                    @endif
                    <span>{{ $opt['label'] }}</span>
                </label>
            @endforeach
            @error('poll') <p class="text-xs text-danger">{{ $message }}</p> @enderror
            <div class="flex flex-wrap items-center gap-3 pt-1">
                <x-ui.button type="submit" size="sm" dusk="poll-submit">Vote</x-ui.button>
                <button type="button" wire:click="toggleResults" class="text-xs text-ink-muted hover:text-ink">View results</button>
                @if (($poll['is_multiple'] ?? false) && ($poll['max_choices'] ?? null))
                    <span class="text-xs text-ink-subtle">Choose up to {{ $poll['max_choices'] }}</span>
                @endif
            </div>
        </form>
    @else
        <div class="space-y-1.5">
            @foreach ($poll['options'] ?? [] as $opt)
                @php($count = (int) ($opt['count'] ?? 0))
                @php($pct = $total > 0 ? (int) round($count * 100 / $total) : 0)
                @php($mine = in_array((int) $opt['id'], $voted, true))
                <div dusk="poll-result-{{ $opt['id'] }}">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-ink {{ $mine ? 'font-semibold' : '' }}">
                            {{ $opt['label'] }}
                            @if ($mine)<span class="text-accent" title="your vote"> ✓</span>@endif
                        </span>
                        <span class="text-xs text-ink-muted nums">{{ $count }} ({{ $pct }}%)</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-raised">
                        <div class="h-full bg-accent" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="flex items-center gap-3 pt-1 text-xs text-ink-subtle">
            <span class="nums">{{ $total }} {{ Str::plural('vote', $total) }}</span>
            @if ($canVote && ! $closed)
                <button type="button" wire:click="toggleResults" class="text-ink-muted hover:text-ink" dusk="poll-revote">{{ $voted ? 'Change vote' : 'Vote' }}</button>
            @endif
        </div>
    @endif
</x-ui.card>
