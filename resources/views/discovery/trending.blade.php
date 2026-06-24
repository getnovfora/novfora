{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'Trending · '.config('app.name', 'NovFora')])

@push('head')
    <link rel="canonical" href="{{ route('trending.index') }}">
@endpush

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Trending']]" />
@endsection

@section('content')
    <x-ui.container size="lg" class="space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight text-ink">Trending</h1>

        <section class="space-y-2">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle font-sans">Trending this week</h2>
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @forelse ($trending as $topic)
                        @include('discovery.partials.topic-line', ['topic' => $topic])
                    @empty
                        <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No recent activity yet — check back soon.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </section>

        <section class="space-y-2">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle font-sans">Best of all time</h2>
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @forelse ($bestOf as $topic)
                        @include('discovery.partials.topic-line', ['topic' => $topic])
                    @empty
                        <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">Nothing here yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </section>
    </x-ui.container>
@endsection
