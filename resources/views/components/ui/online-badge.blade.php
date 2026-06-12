{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A small "online now" dot (P2-M3). Renders only when a real user is active within the last 15 minutes
     (User::isOnline). Pass `user`; absent/offline/deleted → nothing. --}}
@props(['user' => null])
@if ($user && $user->isOnline())
    <span {{ $attributes->class('inline-block h-2 w-2 shrink-0 rounded-full bg-success ring-2 ring-surface-raised') }}
        role="img" aria-label="{{ __('Online now') }}" title="{{ __('Online now') }}"></span>
@endif
