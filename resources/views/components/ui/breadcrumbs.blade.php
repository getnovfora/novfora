{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Breadcrumb trail. Pass :items as a list of ['label' => string, 'url' => ?string]; the last item is the
     current page. --}}
@props(['items' => []])
<nav aria-label="Breadcrumb" {{ $attributes->class('text-sm text-ink-muted') }}>
    <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
        @foreach ($items as $item)
            <li class="flex items-center gap-x-1.5">
                @if (! empty($item['url']) && ! $loop->last)
                    <a href="{{ $item['url'] }}" class="hover:text-ink">{{ $item['label'] }}</a>
                @else
                    <span @class(['text-ink font-medium' => $loop->last]) @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
                @unless ($loop->last)
                    <span class="text-ink-subtle" aria-hidden="true">/</span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
