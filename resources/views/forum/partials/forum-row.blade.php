{{-- SPDX-License-Identifier: Apache-2.0 --}}
<div class="flex items-start gap-3 p-4 hover:bg-surface-sunken">
    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
        <x-ui.icon name="message" class="h-5 w-5" />
    </span>

    <div class="min-w-0 flex-1">
        <a href="{{ route('forums.show', $forum->id) }}" class="block font-semibold text-ink hover:text-accent">{{ $forum->title }}</a>
        @if ($forum->description)
            <p class="mt-0.5 text-sm text-ink-muted">{{ $forum->description }}</p>
        @endif

        {{-- Counts reflow under the title at 360px, then sit inline-right on larger screens. --}}
        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-subtle sm:hidden">
            <div class="flex items-center gap-1">
                <dt class="sr-only">Topics</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->topic_count) }}</dd>
                <span>topics</span>
            </div>
            <div class="flex items-center gap-1">
                <dt class="sr-only">Posts</dt>
                <dd class="nums font-medium text-ink-muted">{{ number_format($forum->post_count) }}</dd>
                <span>posts</span>
            </div>
        </dl>
    </div>

    <dl class="hidden shrink-0 text-right text-xs text-ink-subtle sm:block">
        <div class="flex items-baseline justify-end gap-1">
            <dd class="nums font-semibold text-ink-muted">{{ number_format($forum->topic_count) }}</dd>
            <dt>topics</dt>
        </div>
        <div class="mt-0.5 flex items-baseline justify-end gap-1">
            <dd class="nums font-semibold text-ink-muted">{{ number_format($forum->post_count) }}</dd>
            <dt>posts</dt>
        </div>
    </dl>
</div>
