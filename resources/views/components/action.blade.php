{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{--
    <x-action> — the permission-aware UI contract (NOV-96 / ADR-0109). Wrap a gated control and it resolves to
    one of four in-view outcomes via \App\Permissions\Affordance (the fifth, a route-level friendly-403, is
    \App\Exceptions\FriendlyDenialException):

      • Allow              → renders the live control ($slot).
      • DisabledWithReason → a disabled button carrying `reason` (tooltip + sr-only), when whenDenied="disabled".
      • SignInCta          → a sign-in link, when whenGuest="cta" (signing in could grant the action).
      • Hidden             → renders nothing (the ghost-UI kill — the DEFAULT for a denial with no remedy).

    Props:
      :can        (bool)   the authorization verdict for the current actor (a canDo()/policy result/flag).
      whenDenied  hide|disabled  outcome for an AUTHENTICATED actor who lacks it (default hide).
      whenGuest   hide|cta       outcome for a GUEST (default hide).
      reason      (string) the i18n explanation shown on the disabled state.
      label / icon         the button text/icon for the disabled & CTA renderings (the $slot is the live
                           control for the Allow state; disabled/cta render their own button, so give a label).
      :cta-url / cta-label the sign-in target + text (cta-url defaults to the login route).
--}}
@props([
    'can' => false,
    'whenDenied' => 'hide',
    'whenGuest' => 'hide',
    'reason' => null,
    'label' => null,
    'icon' => null,
    'ctaUrl' => null,
    'ctaLabel' => null,
    'size' => 'sm',
])
@php
    $state = \App\Permissions\Affordance::resolve((bool) $can, auth()->guest(), $whenDenied, $whenGuest);
    $ctaUrl ??= route('login');
@endphp
@if ($state === \App\Permissions\Affordance::Allow)
    {{ $slot }}
@elseif ($state === \App\Permissions\Affordance::DisabledWithReason)
    <x-ui.button type="button" :size="$size" variant="ghost" disabled aria-disabled="true" :title="$reason" {{ $attributes }}>
        @if ($icon)<x-ui.icon :name="$icon" class="h-4 w-4" />@endif
        {{ $label ?? $slot }}
    </x-ui.button>
    @if ($reason)<span class="sr-only">{{ $reason }}</span>@endif
@elseif ($state === \App\Permissions\Affordance::SignInCta)
    <x-ui.button :href="$ctaUrl" :size="$size" variant="ghost" {{ $attributes }}>
        @if ($icon)<x-ui.icon :name="$icon" class="h-4 w-4" />@endif
        {{ $ctaLabel ?? $label ?? $slot }}
    </x-ui.button>
@endif
{{-- Hidden → render nothing --}}
