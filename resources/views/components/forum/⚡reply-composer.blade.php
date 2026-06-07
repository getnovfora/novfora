<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\PostService;
use App\Models\Topic;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $topicId;

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    public function mount(int $topicId): void
    {
        $this->topicId = $topicId;
        $this->ensureCanReply();
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function save(PostService $service, \App\AntiSpam\PostRateLimiter $limiter)
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

        try {
            $service->reply(auth()->user(), $this->topic(), $format, $canonical);
        } catch (\App\AntiSpam\ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('topics.show', $this->topicId, navigate: true);
    }

    private function ensureCanReply(): void
    {
        $topic = $this->topic();
        $user = auth()->user();
        abort_unless(
            $topic->status !== 'locked'
            && $user instanceof User
            && $user->canDo('post.create', $topic->forum->permissionScope()),
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
        <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')"
                          placeholder="Write a reply…" />
    @endif
    @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror

    <div>
        <x-ui.button type="submit">Post reply</x-ui.button>
    </div>
</form>
