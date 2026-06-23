{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Classic forum "Info Center" (ADR-0077): a board-statistics panel + the opt-in who's-online panel, shown
     above the recent-activity feed on the board index. The figures are aggregate counts only (no post content
     or titles), so exposure is identical to the existing ForumStatsWidget — no hidden-forum leak. The data is
     a read-model (App\Forum\InfoCenter): primitives cached 60s, the newest member rehydrated after the cache
     boundary; who's-online stays opt-in via App\Presence\OnlineMembers and renders each member through
     <x-ui.user-name> so the group colour shows (an Administrator reads red).

     COLLAPSIBLE (SMF-style): the heading is a real <button aria-expanded> that toggles the body and persists
     the choice per-browser in localStorage('novfora-infocenter-collapsed'). The no-flash hook lives in the
     board-index @push('head') (mirrors the density/theme pattern): it sets data-infocenter="collapsed" on
     <html> before first paint so the pre-hydration CSS hides the body with no expand-then-collapse jump; Alpine
     then removes the attribute on $nextTick and owns the animated open/close via x-collapse (reduced-motion is
     honoured by the global prefers-reduced-motion block). --}}
@php
    $infoCenter = app(\App\Forum\InfoCenter::class);
    $stats = $infoCenter->statistics();
    $online = $infoCenter->whosOnline();
@endphp
<section
    aria-label="{{ __('Info Center') }}"
    class="space-y-3"
    x-data="{ open: document.documentElement.getAttribute('data-infocenter') !== 'collapsed' }"
    x-init="$nextTick(() => document.documentElement.removeAttribute('data-infocenter'))"
>
    <h2>
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md py-0.5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            @click="open = ! open; try { localStorage.setItem('novfora-infocenter-collapsed', open ? '0' : '1'); } catch (e) {}"
            :aria-expanded="open.toString()"
            aria-controls="info-center-body"
        >
            <span class="text-sm font-semibold text-ink">{{ __('Info Center') }}</span>
            <span class="text-ink-subtle transition-transform" :class="open ? '' : '-rotate-90'">
                <x-ui.icon name="chevron-down" class="h-4 w-4" />
            </span>
        </button>
    </h2>

    <div id="info-center-body" data-infocenter-body x-show="open" x-collapse>
        <div class="grid gap-3 sm:grid-cols-2">
            {{-- Statistics --}}
            <x-ui.card>
                <h3 class="mb-2 text-sm font-semibold text-ink">{{ __('Statistics') }}</h3>
                <dl class="space-y-px">
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
                    <p class="mb-1.5 text-xs text-ink-subtle">
                        {{ trans_choice('{1}:count member online|[2,*]:count members online', $online['count'], ['count' => number_format($online['count'])]) }}
                        {{ __('(in the last :minutes min)', ['minutes' => $online['windowMinutes']]) }}
                    </p>
                    <ul class="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                        @foreach ($online['members'] as $member)
                            <li>
                                <x-ui.user-name :user="$member" :link="true" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</section>
