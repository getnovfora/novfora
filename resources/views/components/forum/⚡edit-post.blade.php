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

<form wire:submit="save" style="display:flex;flex-direction:column;gap:.6rem">
    <h1 style="margin:0">Edit post</h1>

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="12"
                  style="padding:.6rem;border:1px solid #cfcfd6;border-radius:8px;font-family:ui-monospace,monospace"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')" />
    @endif

    <label style="font-size:.85rem;color:#555">Reason for edit (optional)</label>
    <input type="text" wire:model="reason" maxlength="200"
           style="padding:.5rem;border:1px solid #bbb;border-radius:6px">

    <div>
        <button type="submit" style="padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;cursor:pointer">Save changes</button>
    </div>
</form>
