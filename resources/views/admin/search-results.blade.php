{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- ACP search results (ACP v3 · v3-h): admin pages, settings fields, and members matching the query. --}}
@extends('layouts.app', ['title' => 'Admin · Search'])

@section('content')
    <x-admin.shell :title="__('admin.search.heading')">
        @php $hasResults = count($pages) || count($settings) || $members->count(); @endphp

        @if ($q === '')
            <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">
                {{ __('admin.search.prompt') }}
            </div>
        @else
            <p class="text-sm text-ink-muted">{{ __('admin.search.results_for', ['q' => $q]) }}</p>

            @unless ($hasResults)
                <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">
                    {{ __('admin.search.empty', ['q' => $q]) }}
                </div>
            @endunless

            @if (count($pages))
                <section aria-labelledby="acp-search-pages" class="space-y-2">
                    <h2 id="acp-search-pages" class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('admin.search.group_pages') }}</h2>
                    <ul class="divide-y divide-line rounded-lg border border-line bg-surface-raised">
                        @foreach ($pages as $page)
                            <li>
                                <a href="{{ $page['url'] }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                    <span class="truncate text-ink">{{ $page['label'] }}</span>
                                    <span class="shrink-0 text-xs text-ink-subtle">{{ $page['group'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (count($settings))
                <section aria-labelledby="acp-search-settings" class="space-y-2">
                    <h2 id="acp-search-settings" class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('admin.search.group_settings') }}</h2>
                    <ul class="divide-y divide-line rounded-lg border border-line bg-surface-raised">
                        @foreach ($settings as $setting)
                            <li>
                                <a href="{{ $setting['url'] }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                    <span class="truncate text-ink">{{ $setting['label'] }}</span>
                                    <span class="shrink-0 text-xs text-ink-subtle">{{ $setting['group'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($members->count())
                <section aria-labelledby="acp-search-members" class="space-y-2">
                    <h2 id="acp-search-members" class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('admin.search.group_members') }}</h2>
                    <ul class="divide-y divide-line rounded-lg border border-line bg-surface-raised">
                        @foreach ($members as $member)
                            <li>
                                <a href="{{ route('profiles.show', $member) }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                    <span class="truncate text-ink">{{ $member->username ?? $member->display_name ?? $member->name }}</span>
                                    <span class="shrink-0 text-xs text-ink-subtle">{{ __('admin.search.view_member') }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
    </x-admin.shell>
@endsection
