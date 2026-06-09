<?php
// SPDX-License-Identifier: Apache-2.0
use App\Content\RevisionDiffService;
use App\Models\Post;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edit-history diff modal (P2-M1). Rendered per post ONLY when the post has edits AND the viewer may see them
 * (the blade gates visibility; open() re-asserts server-side since Livewire actions are public). Diffs are
 * FORMAT-AWARE (amendment #3) via RevisionDiffService — a bold/link/image-only edit shows, unlike body_text.
 */
new class extends Component
{
    #[Locked]
    public int $postId;

    #[Locked]
    public int $topicId;

    #[Locked]
    public int $editCount = 0;

    public bool $open = false;

    /** @var list<array{editor:?string, at:?string, reason:?string, lines:list<array{type:string,text:string}>}> */
    public array $edits = [];

    public function mount(int $postId, int $topicId, int $editCount = 0): void
    {
        $this->postId = $postId;
        $this->topicId = $topicId;
        $this->editCount = $editCount;
    }

    public function open(RevisionDiffService $differ): void
    {
        $user = auth()->user();
        $post = Post::with(['revisions.editor'])->findOrFail($this->postId);

        // Author may always view their own history; everyone else needs post.history.view at the forum scope.
        $isAuthor = $user instanceof User && (int) $post->user_id === (int) $user->getKey();
        abort_unless(
            $isAuthor || ($user instanceof User && $user->canDo('post.history.view', Scope::thread($this->topicId))),
            403,
        );

        // Each revision snapshots the content BEFORE an edit (with the editor + reason of that edit). The
        // version chain is [rev1 … revN, current]; edit i diffs version[i] → version[i+1] with rev[i]'s meta.
        $revisions = $post->revisions->sortBy('id')->values();
        $versions = $revisions
            ->map(fn ($r): array => ['format' => (string) $r->body_format, 'canonical' => (array) $r->body_canonical])
            ->push(['format' => (string) $post->body_format, 'canonical' => (array) $post->body_canonical])
            ->all();

        $edits = [];
        foreach ($revisions as $i => $rev) {
            $edits[] = [
                'editor' => $rev->editor?->username,
                'at' => $rev->created_at?->toIso8601String(),
                'reason' => $rev->reason,
                'lines' => $differ->diff(
                    $versions[$i]['format'], $versions[$i]['canonical'],
                    $versions[$i + 1]['format'], $versions[$i + 1]['canonical'],
                ),
            ];
        }

        $this->edits = array_reverse($edits); // newest edit first
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->edits = [];
    }
};
?>

<span>
    <x-ui.button type="button" variant="ghost" size="sm" wire:click="open"
                 wire:loading.attr="disabled" wire:target="open" dusk="post-history-btn-{{ $postId }}">
        History ({{ $editCount }})
    </x-ui.button>

    @if ($open)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
             wire:key="ph-modal-{{ $postId }}" dusk="post-history-modal"
             x-on:keydown.escape.window="$wire.close()">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-surface-raised p-5 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-ink">Edit history</h2>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="close" dusk="post-history-close">Close</x-ui.button>
                </div>

                @forelse ($edits as $edit)
                    <div class="mb-4 overflow-hidden rounded-md border border-line">
                        <div class="border-b border-line bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                            <span>Edited</span>
                            @if ($edit['editor'])
                                <span>by <span class="font-medium text-ink">{{ $edit['editor'] }}</span></span>
                            @endif
                            @if ($edit['at'])
                                <span>&middot; {{ Carbon::parse($edit['at'])->diffForHumans() }}</span>
                            @endif
                            @if ($edit['reason'])
                                <span>&middot; <span class="italic">{{ $edit['reason'] }}</span></span>
                            @endif
                        </div>
                        <div class="overflow-x-auto p-2 font-mono text-xs leading-relaxed">
                            @foreach ($edit['lines'] as $line)
                                <div @class([
                                    'whitespace-pre-wrap px-1',
                                    'bg-success-soft text-success-ink' => $line['type'] === 'add',
                                    'bg-danger-soft text-danger-ink line-through' => $line['type'] === 'del',
                                    'text-ink-muted' => $line['type'] === 'same',
                                ])>{{ $line['type'] === 'add' ? '+ ' : ($line['type'] === 'del' ? '- ' : '  ') }}{{ $line['text'] === '' ? ' ' : $line['text'] }}</div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-muted">No edits recorded.</p>
                @endforelse
            </div>
        </div>
    @endif
</span>
