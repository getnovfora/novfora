{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Announcement banners (U4, NOV-102) — dismissible, criteria-targeted. AnnouncementService applies the
     audience + forum-visibility + lifecycle fences (see its docblock), so this view renders ONLY what the
     viewer is permitted to see; it never re-derives targeting here. Server-rendered → the banner is visible
     without JS; Alpine only adds the optimistic hide-on-dismiss. --}}
@inject('announcementService', 'App\Forum\AnnouncementService')
@php($announcements = $announcementService->activeFor(auth()->user()))

@foreach ($announcements as $announcement)
    <div x-data="{ shown: true }" x-show="shown" class="border-b border-line bg-accent-soft text-accent-soft-ink">
        <x-ui.container size="lg" class="flex items-center gap-2 py-2.5 text-sm">
            <x-ui.icon name="bell" class="h-4 w-4 shrink-0" />
            <a href="{{ route('topics.show', $announcement) }}" class="min-w-0 flex-1 truncate font-semibold hover:underline">{{ $announcement->title }}</a>
            @auth
                <form method="POST" action="{{ route('announcements.dismiss', $announcement) }}" x-on:submit="shown = false">@csrf
                    <button type="submit" class="shrink-0 rounded p-1 opacity-70 hover:opacity-100" aria-label="{{ __('announcements.dismiss') }}">
                        <x-ui.icon name="close" class="h-4 w-4" />
                    </button>
                </form>
            @endauth
        </x-ui.container>
    </div>
@endforeach
