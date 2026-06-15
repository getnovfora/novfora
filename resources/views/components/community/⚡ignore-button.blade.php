<?php
// SPDX-License-Identifier: Apache-2.0

use App\Community\IgnoreService;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Profile ignore/unignore control (member tool 2.2). Renders only for a signed-in viewer looking at SOMEONE
 * ELSE's profile. Ignoring is ungated personal participation (no ACL key); authz is just "signed in + not
 * self", re-asserted in the action. The branch is decided from SERVICE state, never the wire payload.
 */
new class extends Component
{
    #[Locked]
    public int $userId;

    public bool $ignoring = false;

    #[Locked]
    public bool $canIgnore = false; // signed in AND viewing someone else

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $viewer = auth()->user();
        $target = User::findOrFail($userId);

        if ($viewer instanceof User && (int) $viewer->getKey() !== (int) $target->getKey()) {
            $this->canIgnore = true;
            $this->ignoring = app(IgnoreService::class)->ignores($viewer, $target);
        }
    }

    public function toggle(IgnoreService $service): void
    {
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);

        $target = User::findOrFail($this->userId);
        abort_if((int) $viewer->getKey() === (int) $target->getKey(), 403);

        if ($service->ignores($viewer, $target)) {
            $service->unignore($viewer, $target);
            $this->ignoring = false;
        } else {
            $service->ignore($viewer, $target);
            $this->ignoring = true;
        }
    }
};
?>

<div dusk="ignore-panel">
    @if ($canIgnore)
        <x-ui.button wire:click="toggle" wire:loading.attr="disabled"
                     :variant="$ignoring ? 'subtle' : 'ghost'" size="sm" dusk="ignore-button"
                     title="{{ $ignoring ? __('You are hiding this member’s posts and PMs') : __('Hide this member’s posts and block their PMs') }}">
            {{ $ignoring ? __('Unignore') : __('Ignore') }}
        </x-ui.button>
    @endif
</div>
