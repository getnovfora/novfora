{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Breadcrumb trail (rendered by layouts.app as a prominent bar directly under the header on board/topic
     pages). Pass :items as a list of ['label' => string, 'url' => ?string]; the last item is the current
     page. A home icon marks the root crumb and chevrons separate the trail (nav-tree style). --}}
@props(['items' => []])
<nav aria-label="Breadcrumb" {{ $attributes->class('text-sm text-ink-muted') }}>
    <ol class="flex flex-wrap items-center gap-x-1 gap-y-1">
        @foreach ($items as $item)
            <li class="flex items-center gap-x-1">
                @unless ($loop->first)
                    <x-ui.icon name="chevron-right" class="h-3.5 w-3.5 text-ink-subtle" />
                @endunless
                @if (! empty($item['url']) && ! $loop->last)
                    <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1 hover:text-ink">
                        @if ($loop->first)<x-ui.icon name="home" class="h-4 w-4" />@endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                @else
                    <span @class(['inline-flex items-center gap-1', 'font-medium text-ink' => $loop->last]) @if ($loop->last) aria-current="page" @endif>
                        @if ($loop->first)<x-ui.icon name="home" class="h-4 w-4" />@endif
                        <span>{{ $item['label'] }}</span>
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
