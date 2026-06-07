{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Labelled text input with validation state. Pulls the error from the validation bag by `name`. --}}
@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
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
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @required($required)
        @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-error"
        @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
        {{ $attributes->class([
            'w-full min-h-11 px-3 rounded-md bg-surface-raised text-ink placeholder:text-ink-subtle border transition-colors',
            'border-line focus:border-accent' => ! $err,
            'border-danger' => $err,
        ]) }}>
    @if ($hint && ! $err)
        <p id="{{ $id }}-hint" class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($err)
        <p id="{{ $id }}-error" class="text-xs text-danger">{{ $err }}</p>
    @endif
</div>
