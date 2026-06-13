{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A layout region outlet (ADR-0032). Renders the admin-configured widgets for a named region. Built-in
     widgets escape their dynamic values and the HTML-block widget sanitises its admin input through the
     post-HTML allowlist, so the value here is trusted, code-authored output — never raw untrusted HTML.
     Emits nothing when the region is empty. --}}
@props(['name'])
@php($regionHtml = app(\App\Theme\LayoutManager::class)->render($name))
@if ($regionHtml !== '')
    <div data-region="{{ $name }}" class="space-y-3">{!! $regionHtml !!}</div>
@endif
