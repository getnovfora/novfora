<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use Livewire\Component;

/**
 * Settings → Preferences (P2-M4). Consolidated per-viewer DISPLAY preferences — posts per thread page, and
 * whether replies read oldest- or newest-first — previously hardcoded in TopicController. OWN-PREFS-ONLY: it
 * never accepts a user id; it always reads and writes auth()->user(), with auth re-asserted in mount() AND in
 * save(). Both values are validated against the User vocabulary on save, so a tampered wire payload cannot
 * persist an out-of-range page size or an unknown sort.
 */
new class extends Component
{
    public int $postsPerPage = User::POSTS_PER_PAGE_DEFAULT;

    public string $threadSort = 'oldest';

    public ?string $flash = null;

    public function mount(): void
    {
        $user = $this->user();
        $this->postsPerPage = $user->postsPerPage();
        $this->threadSort = $user->threadSortNewestFirst() ? 'newest' : 'oldest';
    }

    public function save(): void
    {
        $user = $this->user();

        $perPage = in_array($this->postsPerPage, User::POSTS_PER_PAGE_OPTIONS, true)
            ? $this->postsPerPage : User::POSTS_PER_PAGE_DEFAULT;
        $sort = in_array($this->threadSort, User::THREAD_SORTS, true) ? $this->threadSort : 'oldest';

        $user->forceFill(['posts_per_page' => $perPage, 'thread_sort' => $sort])->save();

        $this->postsPerPage = $perPage;
        $this->threadSort = $sort;
        $this->flash = 'Display preferences saved.';
    }

    /** @return list<int> */
    public function pageSizes(): array
    {
        return User::POSTS_PER_PAGE_OPTIONS;
    }

    private function user(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div class="space-y-5">
    @if ($flash)
        <x-ui.alert variant="success">{{ $flash }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-5">
        <x-ui.card class="space-y-5">
            <x-ui.select label="Posts per page" name="postsPerPage" wire:model="postsPerPage" dusk="pref-per-page"
                         hint="How many posts to show on each page of a thread.">
                @foreach ($this->pageSizes() as $size)
                    <option value="{{ $size }}">{{ $size }} posts</option>
                @endforeach
            </x-ui.select>

            <x-ui.select label="Reply order" name="threadSort" wire:model="threadSort" dusk="pref-thread-sort"
                         hint="Read threads oldest-first (classic) or newest-first.">
                <option value="oldest">Oldest first</option>
                <option value="newest">Newest first</option>
            </x-ui.select>
        </x-ui.card>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="pref-save">
            <span wire:loading.remove wire:target="save">Save preferences</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </form>
</div>
