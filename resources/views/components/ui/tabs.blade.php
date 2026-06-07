{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Horizontal tab navigation (server-rendered links — each tab is a route). Pass :items as a list of
     ['label' => string, 'url' => string, 'active' => bool, 'count' => ?int]. --}}
@props(['items' => []])
<nav aria-label="Tabs" {{ $attributes->class('flex flex-wrap gap-1 border-b border-line') }}>
    @foreach ($items as $it)
        <a href="{{ $it['url'] }}" @if (! empty($it['active'])) aria-current="page" @endif
           @class([
               'inline-flex items-center gap-2 px-4 min-h-11 text-sm font-medium border-b-2 -mb-px',
               'border-accent text-accent' => ! empty($it['active']),
               'border-transparent text-ink-muted hover:text-ink hover:border-line-strong' => empty($it['active']),
           ])>
            {{ $it['label'] }}
            @isset($it['count'])
                <span class="text-xs text-ink-subtle nums">{{ $it['count'] }}</span>
            @endisset
        </a>
    @endforeach
</nav>
