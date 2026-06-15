<?php
// SPDX-License-Identifier: Apache-2.0

use App\Community\IgnoreService;
use App\Models\User;
use Livewire\Component;

/**
 * Settings → Ignored members (member tool 2.2): the signed-in user's ignore list, with one-click unignore.
 * Reads/writes only the authenticated user. Authorisation is just "signed in" (the route is auth-gated and
 * every action re-checks); ignoring carries no ACL key.
 */
new class extends Component
{
    public ?string $message = null;

    /** @return \Illuminate\Support\Collection<int,User> */
    public function rows()
    {
        return app(IgnoreService::class)->ignoredUsers($this->me());
    }

    public function unignore(int $userId): void
    {
        $target = User::find($userId);
        if ($target instanceof User) {
            app(IgnoreService::class)->unignore($this->me(), $target);
            $this->message = 'Removed from your ignore list.';
        }
    }

    private function me(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

<div class="space-y-4" dusk="ignore-list">
    @if ($message)
        <x-ui.alert variant="success">{{ $message }}</x-ui.alert>
    @endif

    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->rows() as $member)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <a href="{{ route('profiles.show', $member) }}" class="min-w-0 flex-1 truncate font-medium text-ink hover:text-accent">
                        {{ $member->display_name ?? $member->username }}
                    </a>
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="unignore({{ $member->id }})"
                                 dusk="unignore-{{ $member->id }}">Unignore</x-ui.button>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">You aren’t ignoring anyone.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
