<?php

// SPDX-License-Identifier: Apache-2.0

use App\Analytics\AnalyticsService;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * The ACP analytics dashboard (ADR-0035) — privacy-conscious AGGREGATE counts only (no PII, no per-user
 * tracking). Headline live totals + a recent-days table from the daily rollup. Admins-only (admin.access +
 * staff-2FA), re-asserted in mount() AND every action. "Refresh" re-runs today's rollup on demand; otherwise
 * the daily cron keeps the series current.
 */
new class extends Component
{
    public ?string $status = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function refresh(): void
    {
        $this->ensureAdmin();
        app(AnalyticsService::class)->rollupRecent(1);
        $this->status = __('Analytics refreshed.');
    }

    /** @return array<string,int> */
    public function totals(): array
    {
        $this->ensureAdmin();

        return app(AnalyticsService::class)->liveTotals();
    }

    /** @return list<array{date:string,users_new:int,topics_new:int,posts_new:int,active_users:int}> */
    public function rows(): array
    {
        $this->ensureAdmin();
        $series = app(AnalyticsService::class)->series(30);

        $byDate = [];
        foreach (['users_new', 'topics_new', 'posts_new', 'active_users'] as $key) {
            foreach ($series[$key] as $point) {
                $byDate[$point['date']][$key] = $point['value'];
            }
        }
        krsort($byDate); // newest first

        $rows = [];
        foreach ($byDate as $date => $values) {
            $rows[] = [
                'date' => $date,
                'users_new' => $values['users_new'] ?? 0,
                'topics_new' => $values['topics_new'] ?? 0,
                'posts_new' => $values['posts_new'] ?? 0,
                'active_users' => $values['active_users'] ?? 0,
            ];
        }

        return $rows;
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
        // Per-section gate (v3-a, ADR-0080): the Analytics page is its own section landing.
        abort_unless($user->canDo('admin.analytics.access', Scope::global()), 403);
    }
};
?>

<div class="space-y-5" dusk="admin-analytics">
    @if ($status) <x-ui.alert variant="success">{{ $status }}</x-ui.alert> @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-subtle">{{ __('Aggregate figures only — no personal data is tracked or shown.') }}</p>
        <x-ui.button size="sm" variant="subtle" wire:click="refresh" dusk="analytics-refresh">{{ __('Refresh today') }}</x-ui.button>
    </div>

    @php($totals = $this->totals())
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'users_total' => __('Members'),
            'topics_total' => __('Topics'),
            'posts_total' => __('Posts'),
            'active_users' => __('Active today'),
        ] as $key => $label)
            <x-ui.card>
                <p class="text-xs uppercase tracking-wide text-ink-subtle">{{ $label }}</p>
                <p class="mt-1 nums text-2xl font-semibold text-ink" dusk="metric-{{ $key }}">{{ number_format($totals[$key] ?? 0) }}</p>
            </x-ui.card>
        @endforeach
    </div>

    @php($rows = $this->rows())
    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-ink-subtle">
                        <th class="px-4 py-2">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('New members') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('New topics') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('New posts') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Active') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-line" dusk="analytics-row">
                            <td class="px-4 py-2 text-ink">{{ $row['date'] }}</td>
                            <td class="px-4 py-2 text-right nums text-ink">{{ number_format($row['users_new']) }}</td>
                            <td class="px-4 py-2 text-right nums text-ink">{{ number_format($row['topics_new']) }}</td>
                            <td class="px-4 py-2 text-right nums text-ink">{{ number_format($row['posts_new']) }}</td>
                            <td class="px-4 py-2 text-right nums text-ink">{{ number_format($row['active_users']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-sm text-ink-subtle">{{ __('No analytics yet — they build up daily (or click Refresh).') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
