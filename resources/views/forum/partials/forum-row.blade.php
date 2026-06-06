{{-- SPDX-License-Identifier: Apache-2.0 --}}
<div style="display:flex;justify-content:space-between;gap:1rem;padding:.7rem 0;border-bottom:1px solid #f0f0f3">
    <div>
        <a href="{{ route('forums.show', $forum->id) }}" style="font-weight:600;font-size:1.05rem;color:#2d2a6b">{{ $forum->title }}</a>
        @if ($forum->description)
            <p style="color:#777;margin:.2rem 0 0;font-size:.9rem">{{ $forum->description }}</p>
        @endif
    </div>
    <div style="color:#999;font-size:.85rem;text-align:right;white-space:nowrap">
        {{ $forum->topic_count }} topics<br>{{ $forum->post_count }} posts
    </div>
</div>
