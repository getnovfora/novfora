<?php

// SPDX-License-Identifier: Apache-2.0

use App\Community\MembersDirectory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Public "Top members" leaderboard (A2) — ranks ACTIVE members by reputation or post count over a chosen
 * timeframe. It shares the members-directory visibility gate (MembersDirectory::visibleTo) and self-guards in
 * mount() AND rows() (a Livewire action carries no route middleware, so the route gate alone is not enough).
 *
 * All-time ranks read the already-denormalised users.reputation_points / post_count columns (cheap). The
 * windowed views aggregate the AUTHORITATIVE source tables instead — reputation from the reputation_events
 * ledger, posts from approved, non-deleted posts in the window — so a windowed board reflects real recent
 * activity and can't be skewed by the lifetime denorm. Ties break deterministically by ascending user id.
 */
new class extends Component
{
    public string $metric = 'reputation'; // reputation | posts

    public string $timeframe = 'all'; // all | month | week

    private const LIMIT = 25;

    public function mount(): void
    {
        abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);
    }

    /** @return Collection<int,User> */
    public function rows(): Collection
    {
        abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);

        $metric = $this->metric === 'posts' ? 'posts' : 'reputation';
        $since = match ($this->timeframe) {
            'week' => now()->subDays(7),
            'month' => now()->subDays(30),
            default => null, // all-time
        };

        $q = User::query()->with('groups')->where('users.status', 'active');

        if ($since === null) {
            // All-time: order by the denormalised column (controlled identifier, never user input).
            $column = $metric === 'posts' ? 'post_count' : 'reputation_points';
            $q->where($column, '>', 0)
                ->select('users.*')
                ->selectRaw("{$column} as metric")
                ->orderByDesc($column)
                ->orderBy('users.id');
        } else {
            // Windowed: aggregate the source of truth, then join the rows back to their (active) users.
            $sub = $metric === 'posts'
                ? DB::table('posts')
                    ->selectRaw('user_id, COUNT(*) as metric')
                    ->where('created_at', '>=', $since)
                    ->where('approved_state', 'approved')
                    ->whereNull('deleted_at')   // DB::table bypasses the SoftDeletes scope — filter explicitly
                    ->whereNotNull('user_id')   // pseudonymised ([Deleted]) authors don't rank
                    ->groupBy('user_id')
                : DB::table('reputation_events')
                    ->selectRaw('user_id, SUM(points) as metric')
                    ->where('created_at', '>=', $since)
                    ->groupBy('user_id')
                    ->havingRaw('SUM(points) > 0');

            $q->joinSub($sub, 'agg', 'agg.user_id', '=', 'users.id')
                ->select('users.*', 'agg.metric')
                ->orderByDesc('agg.metric')
                ->orderBy('users.id');
        }

        return $q->limit(self::LIMIT)->get();
    }
};
?>

<div class="space-y-5" dusk="leaderboard">
    <x-ui.card>
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex flex-col gap-1.5">
                <label for="lb-metric" class="text-sm font-medium text-ink">Rank by</label>
                <select id="lb-metric" wire:model.live="metric"
                        class="min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="reputation">Reputation</option>
                    <option value="posts">Posts</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="lb-timeframe" class="text-sm font-medium text-ink">Timeframe</label>
                <select id="lb-timeframe" wire:model.live="timeframe"
                        class="min-h-11 px-3 rounded-md bg-surface border border-line text-ink focus:border-accent">
                    <option value="all">All time</option>
                    <option value="month">Last 30 days</option>
                    <option value="week">Last 7 days</option>
                </select>
            </div>
        </div>
    </x-ui.card>

    @php($rows = $this->rows())
    @if ($rows->isEmpty())
        <x-ui.card>
            <p class="text-sm text-ink-subtle" dusk="leaderboard-empty">No members to rank for this timeframe yet.</p>
        </x-ui.card>
    @else
        <x-ui.card flush>
            <ol class="divide-y divide-line">
                @foreach ($rows as $i => $member)
                    <li class="flex items-center gap-4 px-4 py-3" dusk="leaderboard-row">
                        <span class="nums w-8 shrink-0 text-center text-lg font-semibold {{ $i < 3 ? 'text-accent' : 'text-ink-subtle' }}"
                              aria-label="Rank {{ $i + 1 }}">{{ $i + 1 }}</span>
                        <a href="{{ route('profiles.show', $member) }}" class="shrink-0">
                            <x-ui.avatar :user="$member" size="md" />
                        </a>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink truncate"><x-ui.user-name :user="$member" :link="true" /></p>
                            <p class="text-xs text-ink-subtle truncate">{{ '@'.$member->username }}</p>
                        </div>
                        <span class="shrink-0 text-right">
                            <span class="nums block font-semibold text-ink" dusk="leaderboard-metric">{{ number_format((int) $member->metric) }}</span>
                            <span class="text-xs text-ink-subtle">{{ $metric === 'posts' ? 'posts' : 'reputation' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    @endif
</div>
