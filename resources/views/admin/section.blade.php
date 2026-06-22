{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A per-section dashboard landing (ACP v3 · v3-h). The shell renders the rail (this section highlighted) +
     the section sidebar; the content here is the section summary + quick-access cards for its sub-pages. As
     each feature slice lands, its section dashboard grows widgets here (foundations §3). --}}
@extends('layouts.app', ['title' => 'Admin · '.__('admin.landing.'.$section.'.title')])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => __('admin.title'), 'url' => route('admin.dashboard')],
        ['label' => __('admin.landing.'.$section.'.title')],
    ]" />
@endsection

@section('content')
    @php
        $clusters = \App\Admin\AdminNavigation::sidebar($section);
        $items = collect($clusters)->flatMap(fn ($c) => $c['items']);
    @endphp

    <x-admin.shell :title="__('admin.landing.'.$section.'.title')" :description="__('admin.landing.'.$section.'.intro')">
        @if ($items->isEmpty())
            <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">
                {{ __('admin.landing_empty') }}
            </div>
        @else
            <p class="text-sm text-ink-muted">{{ __('admin.landing_jump') }}</p>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $item)
                    <a href="{{ $item['url'] }}" @unless ($item['external']) wire:navigate @endunless
                       class="group flex items-center gap-3 rounded-lg border border-line bg-surface-raised p-4 hover:border-accent hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
                            <x-ui.icon :name="$item['icon']" class="h-4.5 w-4.5" />
                        </span>
                        <span class="min-w-0 flex-1 text-sm font-medium text-ink">{{ $item['label'] }}</span>
                        @if ($item['external'])
                            <x-ui.icon name="external" class="h-3.5 w-3.5 shrink-0 text-ink-subtle" />
                        @else
                            <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-subtle group-hover:text-ink-muted" />
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </x-admin.shell>
@endsection
