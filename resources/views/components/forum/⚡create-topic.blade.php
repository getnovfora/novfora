<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $forumId;

    public string $title = '';

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    public function mount(int $forumId): void
    {
        $this->forumId = $forumId;
        $this->ensureCanCreate();
    }

    public function toggleFormat(): void
    {
        $this->format = $this->format === 'markdown' ? 'tiptap_json' : 'markdown';
    }

    public function save(PostService $service, \App\AntiSpam\PostRateLimiter $limiter)
    {
        $this->ensureCanCreate();
        $this->validate(['title' => ['required', 'string', 'min:3', 'max:160']]);

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write something before posting.');

            return null;
        }

        if (! $limiter->attempt(auth()->user())) {
            $this->addError('body', 'You are posting too quickly — please wait a moment and try again.');

            return null;
        }

        [$format, $canonical] = $this->body();

        try {
            $topic = $service->createTopic(auth()->user(), $this->forum(), $this->title, $format, $canonical);
        } catch (\App\AntiSpam\ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('topics.show', $topic, navigate: true);
    }

    private function ensureCanCreate(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('topic.create', $this->forum()->permissionScope()), 403);
    }

    private function forum(): Forum
    {
        return Forum::findOrFail($this->forumId);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function body(): array
    {
        return $this->format === 'markdown'
            ? ['markdown', ['source' => $this->markdownSource]]
            : ['tiptap_json', $this->canonicalJson];
    }

    private function bodyIsEmpty(): bool
    {
        return $this->format === 'markdown'
            ? trim($this->markdownSource) === ''
            : empty($this->canonicalJson['content']);
    }
};
?>

<form wire:submit="save" class="space-y-4">
    <div class="space-y-1.5">
        <label for="ct-title" class="block text-sm font-medium text-ink">Title</label>
        {{-- .blur syncs on blur (not deferred-until-submit): a value typed after a validation-error morph then
             reliably reaches the server on resubmit. No autofocus — it re-fires on every morph and fights the
             editor below for focus. --}}
        <input id="ct-title" type="text" wire:model.blur="title" maxlength="160" dusk="topic-title"
               class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent text-lg">
        @error('title') <p class="text-xs text-danger">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center justify-between gap-2">
        <span class="text-sm text-ink-muted">Body</span>
        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggleFormat">
            {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
        </x-ui.button>
    </div>

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="12" placeholder="Write Markdown…"
                  class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')" />
    @endif
    @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror

    <div>
        <x-ui.button type="submit" size="lg">Post topic</x-ui.button>
    </div>
</form>
