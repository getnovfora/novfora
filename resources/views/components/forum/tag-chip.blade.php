{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A small tag chip that links to the tag's listing page. Renders nothing for a null tag. --}}
@props(['tag' => null])
@if ($tag)
    <a href="{{ route('tags.show', $tag) }}"
       {{ $attributes->class([
           'inline-flex items-center gap-1 rounded-full border border-line bg-surface-sunken',
           'px-2.5 py-0.5 text-xs font-medium text-ink-muted hover:border-accent hover:text-accent',
           'transition-colors',
       ]) }}
       dusk="tag-chip-{{ $tag->id }}">{{ $tag->name }}</a>
@endif
