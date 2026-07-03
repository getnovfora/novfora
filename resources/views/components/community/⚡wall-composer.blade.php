<?php
// SPDX-License-Identifier: Apache-2.0
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\PostRateLimiter;
use App\Community\WallService;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * ⚡wall-composer (◆-lite) — post a status onto a profile wall. The wall id is #[Locked] so the client cannot
 * retarget another user's wall, and save() RE-gates server-side (canPostOn) regardless of whether the trigger
 * was rendered: a guest, an owner-ignored poster, or a vanished owner is refused here. Content flows through
 * WallService (sanitise → moderate → word-filter); nothing client-side is trusted.
 */
new class extends Component
{
    #[Locked]
    public int $profileUserId;

    public array $canonicalJson = ['type' => 'doc', 'content' => []];

    public function mount(int $profileUserId): void
    {
        $this->profileUserId = $profileUserId;
    }

    public function save(WallService $wall, PostRateLimiter $limiter)
    {
        $author = auth()->user();
        $owner = User::find($this->profileUserId);

        abort_unless($author instanceof User && $owner instanceof User && $wall->canPostOn($author, $owner), 403);

        if (empty($this->canonicalJson['content'])) {
            $this->addError('body', __('wall.empty'));

            return null;
        }

        // Share the post rate limiter — a wall status counts as content, so it cannot be used to out-run the
        // per-user write cap the forum composer already enforces.
        if (! $limiter->attempt($author)) {
            $this->addError('body', __('wall.too_fast'));

            return null;
        }

        try {
            $status = $wall->post($author, $owner, 'tiptap_json', $this->canonicalJson);
        } catch (ContentRejectedException $e) {
            $this->addError('body', $e->getMessage());

            return null;
        }

        $this->canonicalJson = ['type' => 'doc', 'content' => []];
        session()->flash('status', $status->approved_state === 'pending' ? __('wall.posted_pending') : __('wall.posted'));

        return $this->redirectRoute('profiles.show', ['user' => $this->profileUserId, 'tab' => 'wall'], navigate: true);
    }
}; ?>

<div>
    <form wire:submit="save" class="space-y-3">
        <x-content-editor model="canonicalJson" :initial="$canonicalJson" />
        @error('body') <p class="text-sm text-danger" dusk="wall-error">{{ $message }}</p> @enderror
        <div class="flex justify-end">
            <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" dusk="wall-post">
                {{ __('wall.post_button') }}
            </x-ui.button>
        </div>
    </form>
</div>
