<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\PostRateLimiter;
use App\Forum\Concerns\ManagesDrafts;
use App\Forum\PostScheduler;
use App\Forum\PostService;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;
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

    public function mount(int $topicId): void
    {
        $this->topicId = $topicId;
        $this->ensureCanReply();
        $this->restoreDraft(); // restore any autosaved reply draft for this topic (own-only)
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
            } catch (\Throwable) {
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
            $service->reply(auth()->user(), $this->topic(), $format, $canonical);
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
            $topic->status !== 'locked'
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

<form wire:submit="save" class="mt-6 space-y-3">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-base font-semibold text-ink">Reply</h2>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat">
            {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
        </x-ui.button>
    </div>

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
