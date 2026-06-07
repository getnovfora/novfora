{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A raised content surface (card). --}}
@props(['flush' => false])
<div {{ $attributes->class([
    'bg-surface-raised border border-line rounded-lg shadow-sm',
    'p-4 sm:p-5' => ! $flush,
]) }}>{{ $slot }}</div>
