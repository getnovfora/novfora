<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\PostService;
use App\Models\Post;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $postId;

    public string $format = 'tiptap_json';

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public string $markdownSource = '';

    public string $reason = '';

    public function mount(int $postId): void
    {
        $post = $this->post();
        abort_unless(auth()->user()?->can('update', $post), 403);

        $this->postId = $postId;
        $this->format = $post->body_format;
        if ($post->body_format === 'markdown') {
            $this->markdownSource = (string) ($post->body_canonical['source'] ?? '');
        } else {
            $this->canonicalJson = $post->body_canonical ?: ['type' => 'doc', 'content' => []];
        }
    }

    public function save(PostService $service)
    {
        $post = $this->post();
        abort_unless(auth()->user()?->can('update', $post), 403);

        [$format, $canonical] = $this->format === 'markdown'
            ? ['markdown', ['source' => $this->markdownSource]]
            : ['tiptap_json', $this->canonicalJson];

        try {
            $service->editPost(auth()->user(), $post, $format, $canonical, $this->reason !== '' ? $this->reason : null);
        } catch (\App\AntiSpam\ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('topics.show', $post->topic_id, navigate: true);
    }

    private function post(): Post
    {
        return Post::findOrFail($this->postId);
    }
};
?>

<form wire:submit="save" class="space-y-4">
    <h1 class="text-2xl font-semibold tracking-tight text-ink">Edit post</h1>

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="12"
                  class="w-full px-3 py-2 rounded-md bg-surface-raised text-ink border border-line focus:border-accent font-mono text-sm"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')" />
    @endif
    @error('body') <p class="text-xs text-danger">{{ $message }}</p> @enderror

    <div class="space-y-1.5">
        <label for="ep-reason" class="block text-sm text-ink-muted">Reason for edit (optional)</label>
        <input id="ep-reason" type="text" wire:model="reason" maxlength="200"
               class="w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border border-line focus:border-accent">
    </div>

    <div>
        <x-ui.button type="submit">Save changes</x-ui.button>
    </div>
</form>
