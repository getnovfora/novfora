<?php
// SPDX-License-Identifier: Apache-2.0
use App\Forum\BookmarkService;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A personal "save / saved" toggle for a topic or post (member tool 2.1). Generic over the target via a short
 * KIND string the service maps to a model — the view never names a class. Authorisation (must be signed in) is
 * re-asserted in the action, since Livewire actions are public. Saving is ungated participation — no ACL key.
 */
new class extends Component
{
    #[Locked]
    public string $kind; // 'topic' | 'post'

    #[Locked]
    public int $targetId;

    public bool $saved = false;

    #[Locked]
    public bool $canSave = false; // the viewer is authenticated (computed by the parent)

    public function mount(string $kind, int $targetId, bool $saved = false, bool $canSave = false): void
    {
        $this->kind = $kind;
        $this->targetId = $targetId;
        $this->saved = $saved;
        $this->canSave = $canSave;
    }

    public function toggle(BookmarkService $service): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $target = $service->resolve($this->kind, $this->targetId);
        abort_unless($target !== null, 404);

        $this->saved = $service->toggle($user, $target);
    }
};
?>

<div dusk="bookmark-{{ $kind }}-{{ $targetId }}">
    @if ($canSave)
        <button type="button" wire:click="toggle" wire:loading.attr="disabled"
            @class([
                'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium transition',
                'border-accent bg-accent-soft text-accent' => $saved,
                'border-line text-ink-muted hover:border-accent hover:text-accent' => ! $saved,
            ])
            aria-pressed="{{ $saved ? 'true' : 'false' }}"
            title="{{ $saved ? 'Saved — remove from your saved items' : 'Save for later' }}">
            <x-ui.icon name="{{ $saved ? 'check' : 'plus' }}" class="h-3.5 w-3.5" />
            <span>{{ $saved ? 'Saved' : 'Save' }}</span>
        </button>
    @endif
</div>
