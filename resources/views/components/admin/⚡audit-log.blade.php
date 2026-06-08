<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\AuditLog;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → System → Audit log (ACP v1, PART 4). A read-only, paginated, filterable view of the append-only
 * audit_log (action prefix, actor, date range). Self-guards like the other admin SFCs. No writes — the log
 * is append-only and is never edited from here.
 */
new class extends Component
{
    use WithPagination;

    public string $action = '';

    public string $actor = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** Reset to page 1 whenever a filter changes (so the view never lands on an out-of-range page). */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['action', 'actor', 'from', 'to']);
        $this->resetPage();
    }

    public function entries()
    {
        $this->ensureAdmin();

        $query = AuditLog::query()->with('actor')->latest('id');

        if ($this->action !== '') {
            $query->where('action', 'like', $this->action.'%');
        }
        if (trim($this->actor) !== '') {
            $ref = trim($this->actor);
            $id = is_numeric($ref)
                ? (int) $ref
                : (int) (User::where('username', $ref)->orWhere('email', $ref)->value('id') ?? -1);
            $query->where('actor_id', $id);
        }
        if ($this->from !== '') {
            $query->whereDate('created_at', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('created_at', '<=', $this->to);
        }

        return $query->paginate(25);
    }

    /** Distinct action prefixes (the part before the first dot) for the quick-filter dropdown. */
    public function prefixes(): array
    {
        return AuditLog::query()
            ->select('action')->distinct()->pluck('action')
            ->map(fn (string $a): string => str_contains($a, '.') ? explode('.', $a)[0] : $a)
            ->unique()->sort()->values()->all();
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    {{-- Filters --}}
    <x-ui.card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div>
                <label for="al-action" class="block text-sm font-medium text-ink mb-1.5">Action prefix</label>
                <select id="al-action" wire:model.live="action"
                        class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="">All actions</option>
                    @foreach ($this->prefixes() as $p)
                        <option value="{{ $p }}.">{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="al-actor" class="block text-sm font-medium text-ink mb-1.5">Actor (id, username, email)</label>
                <input id="al-actor" wire:model.live.debounce.400ms="actor"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
            <div>
                <label for="al-from" class="block text-sm font-medium text-ink mb-1.5">From</label>
                <input id="al-from" type="date" wire:model.live="from"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
            <div>
                <label for="al-to" class="block text-sm font-medium text-ink mb-1.5">To</label>
                <input id="al-to" type="date" wire:model.live="to"
                       class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
            </div>
        </div>
        @if ($action !== '' || $actor !== '' || $from !== '' || $to !== '')
            <div class="mt-3">
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    {{-- Entries --}}
    @php($entries = $this->entries())
    <x-ui.card flush>
        <div class="hidden sm:grid grid-cols-[10rem_1fr_8rem_10rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            <span>Action</span>
            <span>Detail</span>
            <span>Actor</span>
            <span class="text-right">When</span>
        </div>
        <div class="divide-y divide-line">
            @forelse ($entries as $entry)
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[10rem_1fr_8rem_10rem] sm:items-start sm:gap-3 sm:px-5 text-sm">
                    <span class="text-ink"><code class="font-mono break-all">{{ $entry->action }}</code></span>
                    <span class="text-ink-muted break-words">
                        @if ($entry->auditable_type)
                            <span class="text-ink-subtle">{{ class_basename($entry->auditable_type) }}#{{ $entry->auditable_id }}</span>
                        @endif
                        @if (! empty($entry->changes))
                            <code class="font-mono text-xs text-ink-subtle break-all">{{ \Illuminate\Support\Str::limit(json_encode($entry->changes), 120) }}</code>
                        @endif
                    </span>
                    <span class="text-ink-muted truncate">{{ $entry->actor?->username ?? $entry->actor?->display_name ?? 'system' }}</span>
                    <time class="text-ink-subtle sm:text-right" datetime="{{ optional($entry->created_at)->toIso8601String() }}">
                        {{ optional($entry->created_at)->diffForHumans() }}
                    </time>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-ink-subtle sm:px-5">No audit entries match these filters.</p>
            @endforelse
        </div>
    </x-ui.card>

    <div>{{ $entries->links() }}</div>
</div>
