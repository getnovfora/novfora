{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Classic forum "Info Center" (ADR-0077): a board-statistics panel + the opt-in who's-online panel, shown
     above the recent-activity feed on the board index. The figures are aggregate counts only (no post content
     or titles), so exposure is identical to the existing ForumStatsWidget — no hidden-forum leak. The data is
     a read-model (App\Forum\InfoCenter): primitives cached 60s, the newest member rehydrated after the cache
     boundary; who's-online stays opt-in via App\Presence\OnlineMembers. --}}
@php
    $infoCenter = app(\App\Forum\InfoCenter::class);
    $stats = $infoCenter->statistics();
    $online = $infoCenter->whosOnline();
@endphp
<section class="space-y-3" aria-label="{{ __('Info Center') }}">
    <h2 class="text-sm font-semibold text-ink">{{ __('Info Center') }}</h2>

    <div class="grid gap-3 sm:grid-cols-2">
        {{-- Statistics --}}
        <x-ui.card>
            <h3 class="mb-2 text-sm font-semibold text-ink">{{ __('Statistics') }}</h3>
            <dl>
                @foreach ([
                    __('Total posts') => $stats['posts'],
                    __('Total topics') => $stats['topics'],
                    __('Total members') => $stats['members'],
                    __('Posts today') => $stats['postsToday'],
                ] as $label => $value)
                    <div class="flex items-baseline justify-between gap-3 border-b border-line py-1 text-sm">
                        <dt class="text-ink-subtle">{{ $label }}</dt>
                        <dd class="nums font-semibold text-ink">{{ number_format($value) }}</dd>
                    </div>
                @endforeach
                <div class="flex items-baseline justify-between gap-3 py-1 text-sm">
                    <dt class="text-ink-subtle">{{ __('Newest member') }}</dt>
                    <dd class="font-semibold text-ink">
                        @if ($stats['newestMember'])
                            <x-ui.user-name :user="$stats['newestMember']" :link="true" />
                        @else
                            <span class="text-ink-subtle">&mdash;</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Who's Online (opt-in via show_online_status; recent-window from OnlineMembers) --}}
        <x-ui.card>
            <h3 class="mb-2 text-sm font-semibold text-ink">{{ __("Who's Online") }}</h3>
            @if ($online['members']->isEmpty())
                <p class="text-sm text-ink-subtle">{{ __('No one online right now.') }}</p>
            @else
                <p class="mb-1 text-xs text-ink-subtle">
                    {{ trans_choice('{1}:count member online|[2,*]:count members online', $online['count'], ['count' => number_format($online['count'])]) }}
                    {{ __('(in the last :minutes min)', ['minutes' => $online['windowMinutes']]) }}
                </p>
                <ul class="flex flex-wrap gap-x-2 gap-y-1 text-sm">
                    @foreach ($online['members'] as $member)
                        <li>
                            <a class="text-ink hover:text-accent" href="{{ route('profiles.show', $member) }}">{{ $member->username }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</section>
