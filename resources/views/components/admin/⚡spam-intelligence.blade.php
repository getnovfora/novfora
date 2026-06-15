<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\SpamReporter;
use App\Forum\PostService;
use App\Models\Post;
use App\Models\SpamAssessment;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use Livewire\Component;

/**
 * Admin → Spam intelligence (Phase 4 · M6.2). The review surface for posts the M6.1 SpamScorer HELD: it shows
 * each held post with its score, the per-signal breakdown, and the moderation reasons, and lets a moderator
 * approve or reject. Staff-gated in mount() AND every action; approve/reject additionally re-check
 * `topic.moderate` on the post's thread (mirrors ModerationController) — so this never widens authority.
 */
new class extends Component
{
    public ?string $saved = null;

    public function mount(): void
    {
        $this->ensureStaff();
    }

    public function approve(int $assessmentId, PostService $posts): void
    {
        $post = $this->postFor($assessmentId);
        if ($post === null) {
            return;
        }
        $this->authorizePost($post);

        $post->update(['approved_state' => 'approved']);
        Audit::log('post.approved', $post);
        $posts->dispatchPostNotifications($post);
        $this->saved = 'Post approved.';
    }

    public function reject(int $assessmentId, SpamReporter $reporter): void
    {
        $post = $this->postFor($assessmentId);
        if ($post === null) {
            return;
        }
        $this->authorizePost($post);

        $author = $post->author;
        $post->update(['approved_state' => 'rejected']);
        $post->delete(); // soft-delete to the recycle bin — never a hard delete
        Audit::log('post.rejected', $post);

        // Opt-in external reporting (Phase 4 · M6.3): inert unless the admin enabled SFS reporting AND set a key.
        // Post content is included ONLY with the explicit content opt-in (ExternalSignalPolicy::maySubmitContent).
        if ($author instanceof User) {
            $reporter->reportSpammer((string) $post->ip_address, (string) $author->email, (string) $author->username, (string) $post->body_text);
        }

        $this->saved = 'Post rejected.';
    }

    /** @return \Illuminate\Support\Collection<int,SpamAssessment> held posts, highest score first */
    public function items()
    {
        return SpamAssessment::query()
            ->whereHas('post', fn ($q) => $q->where('approved_state', 'pending'))
            ->with(['post.topic', 'user'])
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    private function postFor(int $assessmentId): ?Post
    {
        $assessment = SpamAssessment::with('post.author')->find($assessmentId);
        $post = $assessment?->post;

        return $post instanceof Post ? $post : null;
    }

    private function authorizePost(Post $post): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('topic.moderate', Scope::thread((int) $post->topic_id)), 403);
    }

    private function ensureStaff(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    <p class="text-sm text-ink-muted">
        Posts held by the spam scorer, highest score first. Review the signals, then approve a false positive or reject genuine spam.
        Rejecting soft-deletes to the recycle bin — nothing is ever hard-deleted automatically.
    </p>

    @forelse ($this->items() as $assessment)
        <x-ui.card>
            <div class="space-y-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-ink">
                            {{ $assessment->user?->username ?? 'Unknown' }} ·
                            <a class="text-accent hover:underline" href="{{ $assessment->post?->topic_id ? route('topics.show', $assessment->post->topic_id) : '#' }}">{{ $assessment->post?->topic?->title ?? 'thread' }}</a>
                        </p>
                        <p class="text-xs text-ink-subtle">Score <span class="font-semibold text-ink nums">{{ $assessment->score }}</span></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="subtle" wire:click="approve({{ $assessment->id }})">Approve</x-ui.button>
                        <x-ui.button variant="danger-ghost" wire:click="reject({{ $assessment->id }})">Reject</x-ui.button>
                    </div>
                </div>

                @if (! empty($assessment->signals))
                    <div class="flex flex-wrap gap-2">
                        @foreach ($assessment->signals as $signal => $points)
                            <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                                {{ $signal }} <span class="nums font-semibold text-ink">+{{ $points }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                <p class="line-clamp-3 rounded-md bg-surface-sunken p-3 text-sm text-ink-muted">{{ \Illuminate\Support\Str::limit($assessment->post?->body_text ?? '', 280) }}</p>
            </div>
        </x-ui.card>
    @empty
        <p class="text-sm text-ink-subtle">No held posts to review. 🎉</p>
    @endforelse
</div>
