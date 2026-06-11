{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Hearth's own pagination view (token-styled). Publishing + owning this lets resources/css/app.css drop
     its @source on the framework's pagination Blade in vendor/ — so the asset build needs no Composer and is
     deterministic (PART 5). Registered as the default paginator view in AppServiceProvider. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex items-center justify-between gap-2 text-sm">
        {{-- Mobile: just prev / next --}}
        <div class="flex justify-between flex-1 sm:hidden">
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
        </div>

        {{-- Desktop: full numbered pager --}}
        <div class="hidden sm:flex sm:flex-col sm:flex-1 sm:items-center sm:justify-between sm:flex-row sm:gap-3">
            <p class="text-ink-muted nums">
                {!! __('Showing') !!}
                <span class="font-medium text-ink">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="font-medium text-ink">{{ $paginator->lastItem() }}</span>
                {!! __('of') !!}
                <span class="font-medium text-ink">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>

            <span class="inline-flex items-center gap-1 nums">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                          class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md text-ink-subtle cursor-default">&lsaquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}"
                       class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md text-ink hover:bg-surface-sunken">&lsaquo;</a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true" class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 text-ink-subtle">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page"
                                      class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md bg-accent text-accent-ink font-semibold">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                   class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md text-ink hover:bg-surface-sunken">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}"
                       class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md text-ink hover:bg-surface-sunken">&rsaquo;</a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                          class="inline-flex items-center justify-center min-h-11 min-w-11 px-2 rounded-md text-ink-subtle cursor-default">&rsaquo;</span>
                @endif
            </span>
        </div>
    </nav>
@endif
