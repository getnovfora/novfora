{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Label + hint + error wrapper around an arbitrary control (radios, custom inputs). Pass the control as
     the slot. Provide `error` explicitly, or `name` to pull it from the validation bag. --}}
@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'name' => null,
    'required' => false,
])
@php
    $error = $error ?? ($name ? $errors->first($name) : null);
@endphp
<div class="space-y-1.5">
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-medium text-ink">
            {{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($hint && ! $error)
        <p class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p class="text-xs text-danger">{{ $error }}</p>
    @endif
</div>
