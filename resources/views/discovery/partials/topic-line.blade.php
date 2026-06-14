{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A compact topic line for discovery lists (trending / best-of / related). Expects $topic with forum,
     author, prefix eager-loaded. --}}
<li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-3 sm:px-5 text-sm">
    <a href="{{ route('topics.show', $topic) }}" class="min-w-0 flex-1 truncate font-medium text-ink hover:text-accent">
        <x-forum.prefix-badge :prefix="$topic->prefix" />{{ $topic->title }}
    </a>
    <span class="shrink-0 text-xs text-ink-subtle">
        in <a href="{{ route('forums.show', $topic->forum) }}" class="hover:text-accent">{{ $topic->forum?->title }}</a>
        · <span class="nums">{{ number_format((int) $topic->reply_count) }}</span> {{ \Illuminate\Support\Str::plural('reply', (int) $topic->reply_count) }}
        · <span class="nums">{{ number_format((int) $topic->view_count) }}</span> views
    </span>
</li>
