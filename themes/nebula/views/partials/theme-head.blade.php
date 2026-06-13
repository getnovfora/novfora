{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Nebula theme (Phase-3 dogfood): TOKEN OVERRIDES via the filesystem theme-head seam. It demonstrates the
     documented ThemeApi token contract is overridable WITHOUT touching core: a distinct AA-safe violet accent
     derived by App\Support\AccentPalette (so light + dark inks meet WCAG AA — not hand-picked), plus two of the
     semantic aliases the contract lists (--novfora-accent re-pointed at the accent, --novfora-radius). CSP-nonce'd
     for the strict policy. Filesystem child theme (ThemeManager view override) — distinct from the DB style editor. --}}
@php
    // Nebula violet — a deliberately distinct hue. AccentPalette derives the AA-safe light/dark variable sets.
    $nebulaAccent = \App\Support\AccentPalette::for('#7c3aed');
    $nebulaVars = fn (array $vars): string => collect($vars)->map(fn ($value, $name) => '--'.$name.':'.$value.';')->implode('');
@endphp
@if ($nebulaAccent)
    <style @if (! empty($nonce)) nonce="{{ $nonce }}" @endif data-theme-nebula>
        :root{
            {!! $nebulaVars($nebulaAccent['light']) !!}
            --novfora-accent: var(--accent);   /* re-point a documented semantic alias at the theme accent */
            --novfora-radius: 0.85rem;          /* override a documented semantic token */
        }
        @media (prefers-color-scheme: dark){:root:not([data-theme='light']){ {!! $nebulaVars($nebulaAccent['dark']) !!} }}
        :root[data-theme='dark']{ {!! $nebulaVars($nebulaAccent['dark']) !!} }
    </style>
@endif
