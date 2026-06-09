<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ReactionRateLimiter;
use App\Forum\ReactionService;
use App\Models\Post;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $postId;

    // The post's topic id (a loaded column on the parent) — lets the action build the permission scope
    // without lazy-loading the Topic (N+1 guard on the react path).
    #[Locked]
    public int $topicId;

    /** @var array<string,int> type => count, for this post */
    public array $counts = [];

    /** the viewer's own reaction type on this post, or null */
    public ?string $viewerType = null;

    // Computed ONCE by the parent (react.create is forum-scoped, shared by every post on the page), so the
    // initial render never issues a per-post permission query (N+1) — query-budget discipline.
    #[Locked]
    public bool $canReact = false;

    public function mount(int $postId, int $topicId, array $counts = [], ?string $viewerType = null, bool $canReact = false): void
    {
        $this->postId = $postId;
        $this->topicId = $topicId;
        $this->counts = $counts;
        $this->viewerType = $viewerType;
        $this->canReact = $canReact;
    }

    public function react(string $type, ReactionService $service, ReactionRateLimiter $limiter): void
    {
        $user = auth()->user();
        $scope = Scope::thread($this->topicId);

        // Re-assert authorisation at the action (Livewire actions are public by default). Require BOTH the
        // ability to VIEW the forum — so a misconfigured react.create-without-forum.view can't reach a post in
        // a forum the user cannot see — AND react.create.
        abort_unless(
            $user instanceof User && $user->canDo('forum.view', $scope) && $user->canDo('react.create', $scope),
            403,
        );

        if (! ReactionService::isValidType($type)) {
            abort(422);
        }

        if (! $limiter->attempt($user)) {
            $this->addError('reaction', 'You are reacting too quickly — please slow down.');

            return;
        }

        $post = Post::findOrFail($this->postId);
        $this->viewerType = $service->toggle($user, $post, $type);
        $this->counts = $service->countsForPost($post);
    }
};
?>

<div class="mt-3 flex flex-wrap items-center gap-1.5" dusk="reactions-{{ $postId }}">
    @foreach (config('hearth.reactions.types', []) as $key => $meta)
        @php($count = (int) ($counts[$key] ?? 0))
        @if ($canReact || $count > 0)
            <button type="button"
                @if ($canReact) wire:click="react('{{ $key }}')" wire:loading.attr="disabled" @else disabled @endif
                @class([
                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs transition',
                    'border-accent bg-accent-soft text-accent' => $viewerType === $key,
                    'border-line text-ink-muted hover:border-accent' => $viewerType !== $key && $canReact,
                    'border-line text-ink-subtle cursor-default' => ! $canReact,
                ])
                dusk="react-{{ $postId }}-{{ $key }}"
                title="{{ $meta['label'] }}"
                aria-pressed="{{ $viewerType === $key ? 'true' : 'false' }}">
                <span aria-hidden="true">{{ $meta['emoji'] }}</span>
                @if ($count > 0)<span class="nums" dusk="react-count-{{ $postId }}-{{ $key }}">{{ $count }}</span>@endif
                <span class="sr-only">{{ $meta['label'] }}</span>
            </button>
        @endif
    @endforeach
    @error('reaction') <span class="text-xs text-danger">{{ $message }}</span> @enderror
</div>
