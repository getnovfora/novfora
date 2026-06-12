<?php
// SPDX-License-Identifier: Apache-2.0

use App\AntiSpam\FollowRateLimiter;
use App\Community\FollowService;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Profile follow/unfollow control + follower/following counts (P2-M5). The counts render for everyone
 * (the profile is public); the button renders only for a signed-in viewer looking at someone else's
 * profile. AUTHZ is re-asserted in the action (Livewire actions are public): follow.create to start
 * following (TL-soft-gated + FollowRateLimiter), follow.delete to stop (ungated member participation —
 * a demoted user can always undo their own follow). The follow/unfollow branch is decided from the
 * SERVICE state, never the wire payload; self-follow is refused here AND in FollowService (hard).
 */
new class extends Component
{
    #[Locked]
    public int $userId;

    public bool $following = false;

    public int $followers = 0;

    public int $followingCount = 0;

    // Viewer capabilities, computed once at mount (global-scope checks, resolver-cached). The action
    // re-asserts; these only decide whether the button renders at all.
    #[Locked]
    public bool $canCreate = false;

    #[Locked]
    public bool $canDelete = false;

    public function mount(int $userId): void
    {
        $this->userId = $userId;

        $target = User::findOrFail($userId);
        $viewer = auth()->user();
        $service = app(FollowService::class);

        $this->followers = $service->followerCount($target);
        $this->followingCount = $service->followingCount($target);

        if ($viewer instanceof User && (int) $viewer->getKey() !== (int) $target->getKey()) {
            $this->following = $service->follows($viewer, $target);
            $this->canCreate = $viewer->canDo('follow.create', Scope::global());
            $this->canDelete = $viewer->canDo('follow.delete', Scope::global());
        }
    }

    public function toggle(FollowService $service, FollowRateLimiter $limiter): void
    {
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);

        $target = User::findOrFail($this->userId);
        // Self-follow is a hard refuse (the service throws too; abort first for a clean 403, not a 500).
        abort_if((int) $viewer->getKey() === (int) $target->getKey(), 403);

        if ($service->follows($viewer, $target)) {
            abort_unless($viewer->canDo('follow.delete', Scope::global()), 403);
            $service->unfollow($viewer, $target);
            $this->following = false;
        } else {
            abort_unless($viewer->canDo('follow.create', Scope::global()), 403);

            if (! $limiter->attempt($viewer)) {
                $this->addError('follow', __('You are following too quickly — please slow down.'));

                return;
            }

            $service->follow($viewer, $target);
            $this->following = true;
        }

        $this->followers = $service->followerCount($target);
    }
};
?>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2" dusk="follow-panel">
    <p class="flex items-center gap-3 text-sm text-ink-muted">
        <span><span class="nums font-medium text-ink" dusk="follower-count">{{ $followers }}</span> {{ trans_choice('follower|followers', $followers) }}</span>
        <span class="text-ink-subtle" aria-hidden="true">·</span>
        <span><span class="nums font-medium text-ink" dusk="following-count">{{ $followingCount }}</span> {{ __('following') }}</span>
    </p>

    @if (($following && $canDelete) || (! $following && $canCreate))
        <x-ui.button wire:click="toggle" wire:loading.attr="disabled"
                     :variant="$following ? 'ghost' : 'primary'" size="sm" dusk="follow-button">
            {{ $following ? __('Unfollow') : __('Follow') }}
        </x-ui.button>
    @endif

    @error('follow') <span class="text-xs text-danger">{{ $message }}</span> @enderror
</div>
