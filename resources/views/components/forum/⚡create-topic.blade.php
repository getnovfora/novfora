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

    public function save(PostService $service)
    {
        $this->ensureCanCreate();
        $this->validate(['title' => ['required', 'string', 'min:3', 'max:160']]);

        if ($this->bodyIsEmpty()) {
            $this->addError('body', 'Please write something before posting.');

            return null;
        }

        [$format, $canonical] = $this->body();
        $topic = $service->createTopic(auth()->user(), $this->forum(), $this->title, $format, $canonical);

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

<form wire:submit="save" style="display:flex;flex-direction:column;gap:.6rem">
    <label style="font-weight:600">Title</label>
    <input type="text" wire:model="title" maxlength="160" autofocus
           style="padding:.55rem;border:1px solid #bbb;border-radius:6px;font-size:1.05rem">
    @error('title') <p style="color:#b00020;margin:0">{{ $message }}</p> @enderror

    <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="color:#777;font-size:.85rem">Body</span>
        <button type="button" wire:click="toggleFormat" style="padding:.3rem .6rem;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer;font-size:.8rem">
            {{ $format === 'markdown' ? 'Switch to rich text' : 'Switch to Markdown' }}
        </button>
    </div>

    @if ($format === 'markdown')
        <textarea wire:model="markdownSource" rows="12" placeholder="Write Markdown…"
                  style="padding:.6rem;border:1px solid #cfcfd6;border-radius:8px;font-family:ui-monospace,monospace"></textarea>
    @else
        <x-content-editor model="canonicalJson" :initial="$canonicalJson"
                          :upload-url="route('attachments.store')" :mention-url="route('mentions')" />
    @endif
    @error('body') <p style="color:#b00020;margin:0">{{ $message }}</p> @enderror

    <div>
        <button type="submit" style="padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d2a6b;color:#fff;font-size:1rem;cursor:pointer">Post topic</button>
    </div>
</form>
