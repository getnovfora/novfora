{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- NevoBB's simple (prev/next only) paginator view (token-styled). See nevo.blade.php. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex justify-between gap-2 text-sm">
        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center min-h-11 px-4 rounded-md border border-line text-ink-subtle bg-surface-raised cursor-default">{!! __('pagination.previous') !!}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="inline-flex items-center min-h-11 px-4 rounded-md border border-line text-ink bg-surface-raised hover:border-line-strong hover:bg-surface-sunken">{!! __('pagination.previous') !!}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="inline-flex items-center min-h-11 px-4 rounded-md border border-line text-ink bg-surface-raised hover:border-line-strong hover:bg-surface-sunken">{!! __('pagination.next') !!}</a>
        @else
            <span class="inline-flex items-center min-h-11 px-4 rounded-md border border-line text-ink-subtle bg-surface-raised cursor-default">{!! __('pagination.next') !!}</span>
        @endif
    </nav>
@endif
