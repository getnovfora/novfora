<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\MergeTopicsService;
use App\Forum\TopicModerationException;
use App\Models\Topic;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Merge-this-topic trigger + modal (P2-M4 ◻). A moderator picks a destination topic (same forum, approved,
 * not already a redirect) and confirms; MergeTopicsService runs the whole move + authoritative recount in one
 * transaction and the actor is redirected to the target. Authorization (topic.moderate on both scopes + the
 * rank gate) lives in the service — this component only gathers the input. The source topic id is #[Locked].
 */
new class extends Component
{
    #[Locked]
    public int $topicId;

    public ?int $targetId = null;

    public function merge(): void
    {
        $actor = $this->actor();
        $source = Topic::findOrFail($this->topicId);

        if ($this->targetId === null) {
            return;
        }
        $target = Topic::find((int) $this->targetId);
        if (! $target instanceof Topic) {
            return;
        }

        try {
            app(MergeTopicsService::class)->merge($source, $target, $actor);
        } catch (TopicModerationException $e) {
            session()->flash('error', $e->getMessage());
            $this->redirect(route('topics.show', $this->topicId));

            return;
        }

        session()->flash('status', 'Topic merged.');
        $this->redirect(route('topics.show', $target->getKey()));
    }

    /**
     * Candidate targets for the quick-merge modal: same forum, approved, not this topic, not already a redirect
     * shell. The same-forum scope is a UI DEFAULT (keeps the list short) — the service itself supports a
     * cross-forum merge (it recomputes both forums' counters), gated by topic.moderate on both scopes.
     *
     * @return list<Topic>
     */
    public function candidates(): array
    {
        $source = Topic::find($this->topicId);
        if (! $source instanceof Topic) {
            return [];
        }

        return Topic::query()
            ->where('forum_id', $source->forum_id)
            ->where('id', '!=', $source->getKey())
            ->where('approved_state', 'approved')
            ->whereNull('moved_to_topic_id')
            ->orderByDesc('last_posted_at')->orderByDesc('id')
            ->limit(50)->get()->all();
    }

    private function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div>
    <x-ui.button type="button" variant="ghost" size="sm" dusk="topic-merge"
                 x-on:click="$dispatch('modal-open', 'merge-topic')">Merge</x-ui.button>

    <x-ui.modal name="merge-topic" title="Merge this topic into another">
        <form wire:submit="merge" class="space-y-4">
            <p class="text-sm text-ink-muted">
                Every post here moves into the topic you choose, and this topic becomes a permanent redirect to it.
            </p>
            <x-ui.select label="Destination topic" name="targetId" wire:model="targetId" dusk="merge-target">
                <option value="">— Choose a topic —</option>
                @foreach ($this->candidates() as $t)
                    <option value="{{ $t->id }}">{{ $t->title }}</option>
                @endforeach
            </x-ui.select>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" size="sm" data-modal-close>Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary" size="sm" dusk="merge-confirm">Merge topics</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
