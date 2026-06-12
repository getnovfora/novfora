<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\BulkModerationService;
use App\Forum\SplitTopicService;
use App\Forum\TopicModerationException;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The floating cross-page bulk-action bar (P2-M4 ◐). It reads the selected ids from the Alpine `bulkSelect`
 * store on the client and passes them to a server action; every action re-resolves the actor and runs the
 * selection through BulkModerationService / SplitTopicService, where the forum gate AND the per-item rank
 * guard are enforced — so the ids arriving from the client are never trusted, only the server verdict is. The
 * mount context + ids of the surrounding page are #[Locked]. After acting it flashes an applied/skipped
 * summary and does a FULL redirect, which resets the Alpine store cleanly.
 */
new class extends Component
{
    #[Locked]
    public string $context = 'posts'; // 'posts' (within a topic) | 'topics' (within a forum)

    #[Locked]
    public ?int $topicId = null;

    #[Locked]
    public ?int $forumId = null;

    public string $splitTitle = '';

    public ?int $moveTarget = null;

    /** @param  list<int>  $ids */
    public function deletePosts(array $ids): void
    {
        $result = app(BulkModerationService::class)->deletePosts($this->actor(), $this->ints($ids));
        $this->finish($result, route('topics.show', $this->topicId));
    }

    /** @param  list<int>  $ids */
    public function splitPosts(array $ids): void
    {
        $actor = $this->actor();
        $source = Topic::findOrFail($this->topicId);
        $title = trim($this->splitTitle) !== '' ? trim($this->splitTitle) : 'Split topic';

        try {
            $new = app(SplitTopicService::class)->split($source, $this->ints($ids), $title, $actor);
        } catch (TopicModerationException $e) {
            session()->flash('error', $e->getMessage());
            $this->redirect(route('topics.show', $this->topicId));

            return;
        }

        session()->flash('status', 'Selected posts split into a new topic.');
        $this->redirect(route('topics.show', $new->getKey()));
    }

    /** @param  list<int>  $ids */
    public function deleteTopics(array $ids): void
    {
        $result = app(BulkModerationService::class)->deleteTopics($this->actor(), $this->ints($ids));
        $this->finish($result, route('forums.show', $this->forumId));
    }

    /** @param  list<int>  $ids */
    public function lockTopics(array $ids, bool $lock): void
    {
        $result = app(BulkModerationService::class)->lockTopics($this->actor(), $this->ints($ids), $lock);
        $this->finish($result, route('forums.show', $this->forumId));
    }

    /** @param  list<int>  $ids */
    public function moveTopics(array $ids): void
    {
        if ($this->moveTarget === null) {
            return;
        }
        $result = app(BulkModerationService::class)->moveTopics($this->actor(), $this->ints($ids), (int) $this->moveTarget);
        $this->finish($result, route('forums.show', $this->forumId));
    }

    /** Destination forums the actor may moderate (bulk move) — excludes the current forum. @return list<Forum> */
    public function moveTargets(): array
    {
        $actor = auth()->user();
        if ($this->context !== 'topics' || ! $actor instanceof User) {
            return [];
        }

        return Forum::query()->where('type', 'forum')->orderBy('title')->get()
            ->filter(fn (Forum $f) => (int) $f->id !== (int) $this->forumId && $actor->canDo('topic.moderate', $f->permissionScope()))
            ->values()->all();
    }

    /** @param  list<int>  $ids */
    private function ints(array $ids): array
    {
        return array_values(array_map('intval', $ids));
    }

    /** @param  array{applied: list<int>, skipped: list<int>}  $result */
    private function finish(array $result, string $url): void
    {
        $applied = count($result['applied']);
        $skipped = count($result['skipped']);
        session()->flash('status', "Applied to {$applied} item(s)".($skipped > 0 ? ", skipped {$skipped} (insufficient rank or scope)" : '').'.');

        // navigate:false forces a FULL reload (not an SPA navigate): the Alpine bulkSelect store resets so the
        // selection + bar clear, and the session flash renders on the fresh page.
        $this->redirect($url, navigate: false);
    }

    private function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div x-cloak x-show="$store.bulkSelect.active && $store.bulkSelect.ids.length > 0"
     style="bottom:0;left:0;right:0"
     class="fixed z-40 border-t border-line bg-surface-raised shadow-md">
    <x-ui.container size="lg" class="flex flex-wrap items-center gap-2 py-3">
        <span class="text-sm font-medium text-ink"><span x-text="$store.bulkSelect.ids.length"></span> selected</span>

        @if ($context === 'posts')
            <x-ui.button size="sm" variant="danger-ghost" dusk="bulk-delete"
                         x-on:click="$wire.deletePosts($store.bulkSelect.ids)">Delete</x-ui.button>
            <div class="flex items-center gap-1">
                <input type="text" wire:model="splitTitle" placeholder="New topic title…" dusk="bulk-split-title"
                       class="min-h-9 px-2 rounded-md bg-surface border border-line text-sm text-ink placeholder:text-ink-subtle">
                <x-ui.button size="sm" variant="ghost" dusk="bulk-split"
                             x-on:click="$wire.splitPosts($store.bulkSelect.ids)">Split off</x-ui.button>
            </div>
        @else
            <x-ui.button size="sm" variant="ghost" dusk="bulk-lock" x-on:click="$wire.lockTopics($store.bulkSelect.ids, true)">Lock</x-ui.button>
            <x-ui.button size="sm" variant="ghost" dusk="bulk-unlock" x-on:click="$wire.lockTopics($store.bulkSelect.ids, false)">Unlock</x-ui.button>
            <x-ui.button size="sm" variant="danger-ghost" dusk="bulk-delete" x-on:click="$wire.deleteTopics($store.bulkSelect.ids)">Delete</x-ui.button>
            @if (count($this->moveTargets()) > 0)
                <div class="flex items-center gap-1">
                    <select wire:model="moveTarget" dusk="bulk-move-target"
                            class="min-h-9 px-2 rounded-md bg-surface border border-line text-sm text-ink">
                        <option value="">Move to…</option>
                        @foreach ($this->moveTargets() as $f)
                            <option value="{{ $f->id }}">{{ $f->title }}</option>
                        @endforeach
                    </select>
                    <x-ui.button size="sm" variant="ghost" dusk="bulk-move" x-on:click="$wire.moveTopics($store.bulkSelect.ids)">Move</x-ui.button>
                </div>
            @endif
        @endif

        <button type="button" class="ml-auto min-h-9 px-2 text-sm text-ink-muted hover:text-ink"
                x-on:click="$store.bulkSelect.clear()">Clear</button>
    </x-ui.container>
</div>
