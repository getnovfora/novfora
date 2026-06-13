{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A UI-slot outlet (ADR-0031). Core templates expose named extension points with
     <x-slot-outlet name="topic.sidebar" /> (optionally :context="[...]"). Modules register renderers via
     SlotRegistry::addSlot(); the combined output is SANITISED through the same allowlist as post HTML before
     it reaches the page (a module can never inject <script>/<style> here), so {!! !!} is safe — the value is
     already sanitiser output, not raw module input. Emits nothing when no module has filled the slot. --}}
@props(['name', 'context' => []])
@php($slotHtml = app(\App\Modules\SlotRegistry::class)->render($name, $context))
@if ($slotHtml !== '')
    <div data-slot="{{ $name }}">{!! $slotHtml !!}</div>
@endif
