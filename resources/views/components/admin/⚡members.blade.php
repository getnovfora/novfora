<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\Group;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\ActorRank;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Members → All members (ACP v4 · A1 · ADR-0096). A server-paginated, sortable, filterable directory
 * of every member, with a per-row Manage link into the per-member admin view (A2).
 *
 * APEX (member PII boundary):
 *  - Every entry point — mount(), each action, and the row query (called every render) — re-asserts
 *    admin.access + admin.members.access + staff-2FA. Livewire actions bypass route middleware, so the
 *    component is the authoritative gate, not the route.
 *  - Email and hidden group names are PII/permission-bound: they are queried + rendered ONLY when the actor
 *    holds users.manage, so a restricted admin never sees fields beyond their ceiling (and search never probes email they can't see).
 *  - The sort column is allow-listed: a forged sort key is ignored, never concatenated into orderBy().
 *  - Row actions are links into the per-member view only; mutation buttons remain in A2, while this table still
 *    applies the same capability + rank guard before advertising edit / ban / warn affordances.
 */
new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $group = '';

    public string $trust = '';

    public string $status = '';

    public string $joinedFrom = '';

    public string $joinedTo = '';

    public string $sort = 'created_at';

    public string $dir = 'desc';

    /** Allow-listed sortable columns — a client-supplied sort key is NEVER passed to orderBy() unchecked. */
    private const SORTABLE = ['username', 'email', 'created_at', 'last_active_at', 'trust_level', 'post_count'];

    private const STATUSES = ['active', 'pending', 'suspended', 'banned'];

    public function mount(): void
    {
        $this->ensureCanView();
    }

    /** Any filter change → page 1, so the view never lands on an out-of-range page. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'group', 'trust', 'status', 'joinedFrom', 'joinedTo']);
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        $this->ensureCanView();
        if (! in_array($column, self::SORTABLE, true)) {
            return; // ignore an unknown / forged sort key
        }
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = 'asc';
        }
        $this->resetPage();
    }

    public function members(): LengthAwarePaginator
    {
        $this->ensureCanView();

        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'created_at';
        $dir = $this->dir === 'asc' ? 'asc' : 'desc';
        $canSeeEmail = $this->canSeeEmail();

        return User::query()
            ->with(['groups' => function (BelongsToMany $g) use ($canSeeEmail) {
                if (! $canSeeEmail) {
                    $g->where('is_public', true); // do not leak hidden group names through the primary-group cell
                }
            }]) // primary-group + display + trust read from the loaded collection (no N+1)
            ->when($this->search !== '', function (Builder $q) use ($canSeeEmail) {
                $term = '%'.trim($this->search).'%';
                $q->where(function (Builder $w) use ($term, $canSeeEmail) {
                    $w->where('username', 'like', $term)->orWhere('display_name', 'like', $term);
                    if ($canSeeEmail) {
                        $w->orWhere('email', 'like', $term); // only probe PII the actor may see
                    }
                });
            })
            ->when($this->group !== '', fn (Builder $q) => $q->whereHas('groups', function (Builder $g) {
                $g->whereKey($this->group);
                if (! $this->canSeeEmail()) {
                    $g->where('is_public', true); // a restricted admin can't probe a hidden group's roster via a forged id
                }
            }))
            ->when($this->trust !== '', fn (Builder $q) => $q->where('trust_level', (int) $this->trust))
            ->when(in_array($this->status, self::STATUSES, true), fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->joinedFrom !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $this->joinedFrom))
            ->when($this->joinedTo !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $this->joinedTo))
            ->orderBy($sort, $dir)
            ->orderBy('id') // stable tiebreak so pagination never duplicates / skips a row
            ->paginate(25);
    }

    /** @return Collection<int, Group> */
    public function groupOptions(): Collection
    {
        // Hidden (is_public=false) groups are gated behind the same ceiling as email PII: a restricted admin
        // (admin.members.access but no users.manage) only sees PUBLIC group names in the filter dropdown, so a
        // private group's name/existence never leaks beyond the actor's ceiling (apex-review MEDIUM, ADR-0096).
        return Group::query()
            ->when(! $this->canSeeEmail(), fn (Builder $q) => $q->where('is_public', true))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Email is PII — only an actor who can manage users may see (or search) addresses. */
    public function canSeeEmail(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->canDo('users.manage', Scope::global());
    }

    public function canEdit(User $target): bool
    {
        $u = auth()->user();

        return $u instanceof User
            && $u->canDo('users.manage', Scope::global())
            && $u->id !== $target->id
            && ActorRank::canActOn($u, $target);
    }

    public function canModerate(User $target): bool
    {
        $u = auth()->user();

        return $u instanceof User
            && $u->canDo('bans.manage', Scope::global())
            && $u->id !== $target->id
            && ActorRank::canActOn($u, $target);
    }

    private function ensureCanView(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('admin.members.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    {{-- Filters --}}
    <x-ui.card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 lg:items-end">
            <div class="sm:col-span-2 xl:col-span-2">
                <label for="m-search" class="block text-sm font-medium text-ink mb-1.5">Search</label>
                <input id="m-search" wire:model.live.debounce.400ms="search"
                       placeholder="{{ $this->canSeeEmail() ? 'Username, name, or email' : 'Username or name' }}"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
            <div>
                <label for="m-group" class="block text-sm font-medium text-ink mb-1.5">Group</label>
                <select id="m-group" wire:model.live="group"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">All groups</option>
                    @foreach ($this->groupOptions() as $g)
                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="m-trust" class="block text-sm font-medium text-ink mb-1.5">Trust</label>
                <select id="m-trust" wire:model.live="trust"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">Any trust</option>
                    @for ($i = 0; $i <= 4; $i++)
                        <option value="{{ $i }}">TL{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label for="m-status" class="block text-sm font-medium text-ink mb-1.5">Status</label>
                <select id="m-status" wire:model.live="status"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="suspended">Suspended</option>
                    <option value="banned">Banned</option>
                </select>
            </div>
            <div>
                <label for="m-from" class="block text-sm font-medium text-ink mb-1.5">Joined from</label>
                <input id="m-from" type="date" wire:model.live="joinedFrom"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
            <div>
                <label for="m-to" class="block text-sm font-medium text-ink mb-1.5">Joined to</label>
                <input id="m-to" type="date" wire:model.live="joinedTo"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
        </div>
        @if ($search !== '' || $group !== '' || $trust !== '' || $status !== '' || $joinedFrom !== '' || $joinedTo !== '')
            <div class="mt-3">
                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    {{-- Member table --}}
    @php($members = $this->members())
    @php($canSeeEmail = $this->canSeeEmail())
    @php($canManage = Route::has('admin.members.show'))
    @php($cols = 7 + ($canSeeEmail ? 1 : 0))

    <x-ui.table label="All members" sticky>
        <x-slot:head>
            <tr>
                @foreach ([['username', 'Username'], ...($canSeeEmail ? [['email', 'Email']] : []), ['__group', 'Primary group'], ['trust_level', 'Trust'], ['__status', 'Status'], ['created_at', 'Joined'], ['last_active_at', 'Last active']] as [$col, $label])
                    @php($sortable = in_array($col, ['username', 'email', 'trust_level', 'created_at', 'last_active_at'], true))
                    <th @if ($sortable && $sort === $col) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        @if ($sortable)
                            <button type="button" wire:click="sortBy('{{ $col }}')"
                                    class="inline-flex items-center gap-1 rounded uppercase tracking-wide hover:text-ink focus-visible:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                    dusk="sort-{{ $col }}">
                                {{ $label }}
                                @if ($sort === $col)
                                    <x-ui.icon :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" class="h-3 w-3" />
                                @endif
                            </button>
                        @else
                            {{ $label }}
                        @endif
                    </th>
                @endforeach
                <th class="text-right">Actions</th>
            </tr>
        </x-slot:head>

        @forelse ($members as $member)
            @php($primary = $member->groups->firstWhere('pivot.is_primary', true) ?? $member->groups->sortByDesc('priority')->first())
            <tr wire:key="member-{{ $member->id }}">
                <td>
                    <div class="font-medium text-ink"><x-ui.user-name :user="$member" /></div>
                    @if ($member->username)
                        <div class="text-xs text-ink-subtle">{{ '@'.$member->username }}</div>
                    @endif
                </td>
                @if ($canSeeEmail)
                    <td class="text-ink-muted break-all">{{ $member->email }}</td>
                @endif
                <td class="text-ink-muted">{{ $primary?->name ?? '—' }}</td>
                <td><x-ui.badge variant="accent">TL{{ $member->trustLevel() }}</x-ui.badge></td>
                <td>
                    @php($tone = ['active' => 'success', 'pending' => 'warn', 'suspended' => 'warn', 'banned' => 'danger'][$member->status] ?? 'neutral')
                    <x-ui.badge :variant="$tone">{{ ucfirst((string) $member->status) }}</x-ui.badge>
                </td>
                <td class="text-ink-subtle whitespace-nowrap">
                    <time datetime="{{ optional($member->created_at)->toIso8601String() }}">{{ optional($member->created_at)->format('M j, Y') }}</time>
                </td>
                <td class="text-ink-subtle whitespace-nowrap">
                    @if ($member->last_active_at)
                        <time datetime="{{ \Illuminate\Support\Carbon::parse($member->last_active_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($member->last_active_at)->diffForHumans() }}</time>
                    @else
                        —
                    @endif
                </td>
                <td class="text-right">
                    @if ($canManage)
                        <div class="inline-flex flex-wrap justify-end gap-1" aria-label="Member actions for {{ $member->username ?? $member->id }}">
                            <x-ui.button :href="route('admin.members.show', $member)" variant="ghost" size="sm" dusk="member-view-{{ $member->id }}">View</x-ui.button>
                            @if ($this->canEdit($member))
                                <x-ui.button :href="route('admin.members.show', $member).'#group-membership'" variant="ghost" size="sm" dusk="member-edit-{{ $member->id }}">Edit</x-ui.button>
                            @endif
                            @if ($this->canModerate($member))
                                <x-ui.button :href="route('admin.members.show', $member).'#ban-member'" variant="ghost" size="sm" dusk="member-ban-{{ $member->id }}">Ban</x-ui.button>
                                <x-ui.button :href="route('admin.members.show', $member).'#warn-member'" variant="ghost" size="sm" dusk="member-warn-{{ $member->id }}">Warn</x-ui.button>
                            @endif
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $cols + 1 }}" class="px-3 py-8 text-center text-sm text-ink-subtle">No members match these filters.</td>
            </tr>
        @endforelse
    </x-ui.table>

    <div>{{ $members->links() }}</div>
</div>
