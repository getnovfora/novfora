{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Labelled select with validation state. Options are passed as the slot (<option> tags). --}}
@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'id' => null,
    'required' => false,
])
@php
    $id = $id ?? $name;
    $err = $name ? $errors->first($name) : null;
@endphp
<div class="space-y-1.5">
    @if ($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-ink">
            {{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    <select id="{{ $id }}" name="{{ $name }}" @required($required)
        @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->class([
            'w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink border transition-colors',
            'border-line focus:border-accent' => ! $err,
            'border-danger' => $err,
        ]) }}>{{ $slot }}</select>
    @if ($hint && ! $err)
        <p class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($err)
        <p id="{{ $id }}-error" class="text-xs text-danger">{{ $err }}</p>
    @endif
</div>
