{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Labelled textarea with validation state. --}}
@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'id' => null,
    'rows' => 4,
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
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @required($required)
        @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-error"
        @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
        {{ $attributes->class([
            'w-full px-3 py-2 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border transition-colors',
            'border-line focus:border-accent' => ! $err,
            'border-danger' => $err,
        ]) }}>{{ $slot }}</textarea>
    @if ($hint && ! $err)
        <p id="{{ $id }}-hint" class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($err)
        <p id="{{ $id }}-error" class="text-xs text-danger">{{ $err }}</p>
    @endif
</div>
