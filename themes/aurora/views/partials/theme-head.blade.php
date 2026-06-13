{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Aurora example theme (A4): a distinct, AA-safe accent palette injected via the filesystem theme-head
     override point. It reuses App\Support\AccentPalette so the light AND dark accent inks are computed to meet
     WCAG 2.1 AA contrast — exactly the machinery the Appearance accent and the DB style editor use — rather
     than hand-picked colours that might fail AA. CSP-nonce'd for the strict policy. This is a filesystem child
     theme (ThemeManager view override); it never touches the DB style editor (ADR-0029). --}}
@php
    // Aurora teal — a deliberately distinct hue from the default accent. AccentPalette derives the AA-safe
    // light/dark variable sets (accent, accent-ink, accent-hover, accent-soft, accent-soft-ink, focus).
    $auroraAccent = \App\Support\AccentPalette::for('#0e7490');
    $auroraVars = fn (array $vars): string => collect($vars)->map(fn ($value, $name) => '--'.$name.':'.$value.';')->implode('');
@endphp
@if ($auroraAccent)
    <style @if (! empty($nonce)) nonce="{{ $nonce }}" @endif data-theme-aurora>
        :root{ {!! $auroraVars($auroraAccent['light']) !!} }
        @media (prefers-color-scheme: dark){:root:not([data-theme='light']){ {!! $auroraVars($auroraAccent['dark']) !!} }}
        :root[data-theme='dark']{ {!! $auroraVars($auroraAccent['dark']) !!} }
    </style>
@endif
