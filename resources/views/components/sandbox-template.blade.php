{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Renders an overridable sandbox template by key (ADR-0038). The output is produced by the restricted
     sandbox renderer (dynamic {{ }} values auto-escaped; literal structure linted at save), so {!! !!} here
     is safe. Renders nothing unless an admin has enabled an override for this key. --}}
@props(['name', 'data' => []])
@php($sandboxHtml = app(\App\Theme\Sandbox\TemplateService::class)->render($name, is_array($data) ? $data : []))
@if ($sandboxHtml !== '')
    <div data-sandbox-template="{{ $name }}">{!! $sandboxHtml !!}</div>
@endif
