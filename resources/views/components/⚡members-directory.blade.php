<?php
// SPDX-License-Identifier: Apache-2.0
use App\Community\MembersDirectory;
use App\Models\Group;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public members directory listing — search, sort, and filter by group / trust level (P2 community).
 * Visibility is admin-controlled: this self-guards in mount() AND members() via MembersDirectory::visibleTo()
 * (a Livewire action reaches the component with no route middleware, so the route gate alone is not enough).
 * Only ACTIVE members are listed (banned/other statuses are excluded). Reads only already-denormalised
 * columns (post_count, reputation_points, last_active_at) + the eager-loaded groups for the name colour.
 */
new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sort = 'joined';

    public string $group = '';

    public string $trust = '';

    public function mount(): void
    {
        abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);
    }

    /** Any filter change returns to page 1 (so the view never lands on an out-of-range page). */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'sort', 'group', 'trust']);
        $this->sort = 'joined';
        $this->resetPage();
    }

    public function members()
    {
        abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);

        $q = User::query()->with('groups')->where('status', 'active');

        $term = trim($this->search);
        if (strlen($term) >= 2) {
            $q->where(fn ($w) => $w->where('username', 'like', "%{$term}%")->orWhere('display_name', 'like', "%{$term}%"));
        }
        if ($this->group !== '') {
            $slug = $this->group;
            $q->whereHas('groups', fn ($g) => $g->where('slug', $slug));
        }
        if ($this->trust !== '') {
            $q->where('trust_level', (int) $this->trust);
        }

        $q = match ($this->sort) {
            'posts' => $q->orderByDesc('post_count'),
            'reputation' => $q->orderByDesc('reputation_points'),
            'name' => $q->orderBy('username'),
            'active' => $q->orderByDesc('last_active_at'),
            default => $q->orderByDesc('created_at'),
        };

        return $q->paginate(24);
    }

    /** Filterable groups (everything except the Guests base group), highest rank first. @return list<array{slug:string,name:string}> */
    public function groupOptions(): array
    {
        return Group::query()->where('slug', '!=', 'guests')->orderByDesc('priority')->orderBy('name')
            ->get(['slug', 'name'])
            ->map(fn (Group $g): array => ['slug' => (string) $g->slug, 'name' => (string) $g->name])->all();
    }
};
?>

<div class="space-y-5" dusk="members-directory">
    {{-- Filters --}}
    <x-ui.card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div>
                <label for="md-q" class="block text-sm font-medium text-ink mb-1.5">Search</label>
                <input id="md-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Username or name"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink placeholder:text-ink-subtle focus:border-accent">
            </div>
            <div>
                <label for="md-sort" class="block text-sm font-medium text-ink mb-1.5">Sort by</label>
                <select id="md-sort" wire:model.live="sort"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="joined">Newest members</option>
                    <option value="active">Recently active</option>
                    <option value="posts">Most posts</option>
                    <option value="reputation">Most reputation</option>
                    <option value="name">Name (A–Z)</option>
                </select>
            </div>
            <div>
                <label for="md-group" class="block text-sm font-medium text-ink mb-1.5">Group</label>
                <select id="md-group" wire:model.live="group"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">All groups</option>
                    @foreach ($this->groupOptions() as $opt)
                        <option value="{{ $opt['slug'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="md-trust" class="block text-sm font-medium text-ink mb-1.5">Trust level</label>
                <select id="md-trust" wire:model.live="trust"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">Any trust level</option>
                    <option value="0">TL0 — new</option>
                    <option value="1">TL1 — basic</option>
                    <option value="2">TL2 — member</option>
                    <option value="3">TL3 — regular</option>
                    <option value="4">TL4 — leader</option>
                </select>
            </div>
        </div>
        @if ($search !== '' || $sort !== 'joined' || $group !== '' || $trust !== '')
            <div class="mt-3">
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    @php($members = $this->members())

    @if ($members->isEmpty())
        <x-ui.card>
            <p class="text-sm text-ink-subtle">No members match your filters.</p>
        </x-ui.card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($members as $member)
                <x-ui.card>
                    <div class="flex items-start gap-3">
                        <a href="{{ route('profiles.show', $member) }}" class="shrink-0">
                            <x-ui.avatar :user="$member" size="lg" />
                        </a>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink truncate">
                                <x-ui.user-name :user="$member" :link="true" />
                            </p>
                            <p class="text-xs text-ink-subtle truncate">{{ '@'.$member->username }}</p>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs text-ink-muted">
                                <div><dt class="sr-only">Joined</dt><dd>Joined {{ $member->created_at?->isoFormat('MMM YYYY') }}</dd></div>
                                <div><dt class="sr-only">Posts</dt><dd class="nums">{{ number_format((int) $member->post_count) }} posts</dd></div>
                                <div><dt class="sr-only">Reputation</dt><dd class="nums">{{ number_format((int) $member->reputation_points) }} rep</dd></div>
                                @if ($member->isOnline())
                                    <div class="flex items-center gap-1.5"><x-ui.online-badge :user="$member" /> Online</div>
                                @endif
                            </dl>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <div>{{ $members->links() }}</div>
    @endif
</div>
