<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\PostRateLimiter;
use App\Forum\Concerns\ManagesDrafts;
use App\Forum\PostScheduler;
use App\Forum\PostService;
use App\Models\CannedReply;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesDrafts;

    #[Locked]
    public int $topicId;

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    /** Optional "publish at" (datetime-local string) — when set + future, the reply is scheduled (2.4). */
    public ?string $publishAt = null;

    /** Multi-quote basket cap (U1) — one reply never pulls in more than this many source posts. */
    private const MAX_QUOTES = 10;

    /** Quote-reply linkage (M1/U1) — the FIRST quoted post; set server-side, #[Locked] so the client can't forge it. */
    #[Locked]
    public ?int $replyToPostId = null;

    public function mount(int $topicId, ?string $quote = null, ?int $canned = null): void
    {
        $this->topicId = $topicId;
        $this->ensureCanReply();

        // A Quote param (?quote={id} single, or ?quote=1,2,3 multi — U1's selection basket) pre-fills the composer
        // with an attributed blockquote per source post and links the reply to the first; a canned reply
        // (?canned={id}, T1, staff-only/bans.manage) pre-fills a stock body. Either takes precedence over an
        // autosaved draft for this load; quote wins if both are present.
        if ($quote !== null && $this->applyQuotes($quote)) {
            return;
        }
        if ($canned !== null && $this->applyCanned($canned)) {
            return;
        }

        $this->restoreDraft(); // restore any autosaved reply draft for this topic (own-only)
    }

    /** U1 multi-quote: parse a comma-separated id list, keep only APPROVED posts in THIS topic (so a forged or
     *  cross-topic id can never pull in content), de-duplicate, preserve the requested order, and cap at
     *  MAX_QUOTES. Builds ONE doc with an attributed blockquote per source; the parent linkage points at the
     *  first. Returns false (→ draft fallback) if none resolve. One batched query, never a per-id N+1. */
    private function applyQuotes(string $quote): bool
    {
        $ids = collect(explode(',', $quote))
            ->map(fn ($v): int => (int) trim((string) $v))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(self::MAX_QUOTES)
            ->values();

        if ($ids->isEmpty()) {
            return false;
        }

        $found = Post::query()
            ->whereIn('id', $ids->all())
            ->where('topic_id', $this->topicId)
            ->where('approved_state', 'approved')
            ->get()
            ->keyBy('id');

        $ordered = $ids->map(fn (int $id): ?Post => $found->get($id))->filter()->values();
        if ($ordered->isEmpty()) {
            return false;
        }

        $this->canonicalJson = $this->buildQuotedDoc($ordered->all());
        $this->replyToPostId = (int) $ordered->first()->id;

        return true;
    }

    /** Canned replies are a moderator tool — only a bans.manage holder may insert/see them. */
    private function canUseCanned(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->canDo('bans.manage', Scope::global());
    }

    private function applyCanned(int $id): bool
    {
        if (! $this->canUseCanned()) {
            return false;
        }
        $reply = CannedReply::where('id', $id)->where('is_active', true)->first();
        if ($reply === null) {
            return false;
        }
        $this->canonicalJson = (array) $reply->body_canonical;

        return true;
    }

    /** A canonical-JSON doc: for EACH quoted post, an attribution line linking to the source + a blockquote of the
     *  excerpt (the plain-text projection — never re-embedding another post's nodes/attachments), then a trailing
     *  empty paragraph for the reply. Attribution depth (U1): every source keeps its own author + permalink.
     *
     *  @param  list<Post>  $posts */
    private function buildQuotedDoc(array $posts): array
    {
        $content = [];
        foreach ($posts as $quoted) {
            $author = $quoted->author;
            $name = $author?->display_name ?? $author?->username ?? 'A member';
            $excerpt = trim(Str::limit((string) $quoted->body_text, 600));
            $content[] = ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => $name.' wrote:',
                'marks' => [['type' => 'link', 'attrs' => ['href' => '#post-'.$quoted->id]]],
            ]]];
            $content[] = ['type' => 'blockquote', 'content' => [
                ['type' => 'paragraph', 'content' => $excerpt !== '' ? [['type' => 'text', 'text' => $excerpt]] : []],
            ]];
        }
        $content[] = ['type' => 'paragraph', 'content' => []];

        return ['type' => 'doc', 'content' => $content];
    }

    /** @return Collection<int, CannedReply> active canned replies for the staff picker (empty for non-staff). */
    public function cannedReplyOptions(): Collection
    {
        return $this->canUseCanned()
            ? CannedReply::where('is_active', true)->orderBy('title')->get(['id', 'title'])
            : collect();
    }

    /** @return array{0:string,1:int} */
    protected function draftContext(): array
    {
        return ['reply', $this->topicId];
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function save(PostService $service, PostRateLimiter $limiter, PostScheduler $scheduler)
    {
        $this->ensureCanReply();

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write a reply.');

            return null;
        }

        if (! $limiter->attempt(auth()->user())) {
            $this->addError('body', 'You are posting too quickly — please wait a moment and try again.');

            return null;
        }

        [$format, $canonical] = $this->format === 'markdown'
            ? ['markdown', ['source' => $this->markdownSource]]
            : ['tiptap_json', $this->canonicalJson];

        // Scheduled for later (member tool 2.4) → hold it; the publish cron creates the real reply at that time.
        if ($this->publishAt !== null && trim($this->publishAt) !== '') {
            try {
                $when = Carbon::parse($this->publishAt);
            } catch (Throwable) {
                $this->addError('publishAt', 'That date and time is invalid.');

                return null;
            }
            if (! $when->isFuture()) {
                $this->addError('publishAt', 'Choose a time in the future.');

                return null;
            }

            $scheduler->scheduleReply(auth()->user(), $this->topic(), $format, $canonical, $when);
            $this->discardDraft();
            session()->flash('status', 'Your reply is scheduled.');

            return $this->redirectRoute('scheduled.index', navigate: true);
        }

        try {
            $service->reply(auth()->user(), $this->topic(), $format, $canonical, $this->replyToPostId);
        } catch (ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        $this->discardDraft(); // published — drop the autosaved draft

        return $this->redirectRoute('topics.show', $this->topicId, navigate: true);
    }

    private function ensureCanReply(): void
    {
        $topic = $this->topic();
        $user = auth()->user();
        abort_unless(
            $topic->isReplyable()
            && $user instanceof User
            && $user->canDo('post.create', $topic->forum->permissionScope())
            // Club forums (M1.4): replying also requires active club membership (or staff).
            && $topic->forum->clubParticipationAllowed($user),
            403,
        );
    }

    private function topic(): Topic
    {
        return Topic::with('forum')->findOrFail($this->topicId);
    }

    private function bodyIsEmpty(): bool
    {
        return $this->format === 'markdown'
            ? trim($this->markdownSource) === ''
            : empty($this->canonicalJson['content']);
    }
};
?>

<form wire:submit="save" id="reply-composer" class="mt-6 space-y-3">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-base font-semibold text-ink">Reply</h2>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat">
            {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
        </x-ui.button>
    </div>

    {{-- T1: staff canned-reply picker — each link reloads the composer pre-filled with that reply's body. --}}
    @php($cannedOptions = $this->cannedReplyOptions())
    @if ($cannedOptions->isNotEmpty())
        <details class="text-sm">
            <summary class="inline-flex cursor-pointer items-center gap-1 text-ink-muted hover:text-ink">
                <x-ui.icon name="message" class="h-4 w-4" /> Insert a canned reply
            </summary>
            <ul class="mt-2 space-y-0.5 rounded-md border border-line bg-surface-raised p-2">
                @foreach ($cannedOptions as $opt)
                    <li>
                        <a href="{{ route('topics.show', $this->topicId).'?canned='.$opt->id.'#reply-composer' }}"
                           class="block rounded px-2 py-1 text-accent hover:bg-surface-sunken" dusk="canned-insert-{{ $opt->id }}">{{ $opt->title }}</a>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="6" placeholder="Write Markdown…"
                  class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson" draft
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')"
                          placeholder="Write a reply…" />
    @endif
    @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button type="submit">{{ ($publishAt ?? '') !== '' ? 'Schedule reply' : 'Post reply' }}</x-ui.button>
        {{-- Member tool 2.4: schedule this reply for a future time. --}}
        <label class="flex items-center gap-1.5 text-xs text-ink-muted">
            <span class="hidden sm:inline">Schedule for</span>
            <input type="datetime-local" wire:model.live="publishAt" dusk="reply-schedule-at"
                   class="rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink" />
        </label>
        @if (($publishAt ?? '') !== '')
            <button type="button" wire:click="$set('publishAt', null)" class="text-xs text-accent hover:underline">clear</button>
        @endif
        @if ($draftRestored)
            <span class="text-xs text-ink-subtle" dusk="reply-draft-restored">
                Draft restored ·
                <button type="button" wire:click="discardDraft" class="text-accent hover:underline" dusk="reply-draft-discard">Discard</button>
            </span>
        @endif
    </div>
    @error('publishAt') <p class="text-xs text-danger">{{ $message }}</p> @enderror
</form>
