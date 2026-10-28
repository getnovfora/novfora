{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- U11 (ADR-0112): a named template-hook anchor. Renders every ENABLED admin-authored sandbox fragment
     attached to this anchor (TemplateContract::hooks()), in position order — nothing when there are none.
     Raw output is safe by construction: the sandbox auto-escapes every dynamic value and the save-time lint
     forbids script/style/handler literals (same rationale as <x-sandbox-template>). --}}
@props(['name', 'data' => []])
@php($hookHtml = app(\App\Theme\Sandbox\TemplateService::class)->renderHooks($name, $data))
@if ($hookHtml !== '')
    <div {{ $attributes }} data-template-hook="{{ $name }}">{!! $hookHtml !!}</div>
@endif
