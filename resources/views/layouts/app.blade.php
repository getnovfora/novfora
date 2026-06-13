{{-- SPDX-License-Identifier: Apache-2.0 --}}
@php
    // Appearance (default-theme PART 2). Signed-in users carry an authoritative server value (so the theme
    // applies with NO JavaScript); guests get auto/comfortable here and the inline boot snippet refines from
    // localStorage before paint. $serverColorMode/$serverDensity are null for guests (→ the snippet reads
    // localStorage); $htmlTheme is set only for an explicit light/dark choice (auto = let the media query run).
    $authUser = auth()->user();
    $serverColorMode = $authUser?->color_mode;
    $serverDensity = $authUser?->density;

    // Site-level appearance (ACP v1) — resolved inline (memoised: one cache read/request). Guests inherit
    // the site defaults.
    $site = app(\App\Settings\Settings::class)->siteView();
    $siteDefaultMode = in_array($site['default_color_mode'] ?? 'auto', ['auto', 'light', 'dark'], true) ? $site['default_color_mode'] : 'auto';
    $siteDefaultDensity = ($site['default_density'] ?? 'comfortable') === 'compact' ? 'compact' : 'comfortable';

    $colorMode = $serverColorMode ?: $siteDefaultMode;
    $density = $serverDensity ?: $siteDefaultDensity;
    $htmlTheme = in_array($colorMode, ['light', 'dark'], true) ? $colorMode : null;
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $wordmark = ($site['wordmark'] ?? '') !== '' ? $site['wordmark'] : config('app.name', 'NovFora');

    // Accent + forum-width overrides emitted as CSS variables (AA-safe, light+dark). Width comes from a
    // fixed map; accent is validated hex (AccentPalette returns null otherwise) — safe to inline.
    $accent = \App\Support\AccentPalette::for($site['accent_color'] ?? '');
    $widthCss = ['boxed-narrow' => '48rem', 'standard' => '64rem', 'wide' => '80rem', 'full' => '100%'][$site['forum_width'] ?? 'standard'] ?? '64rem';
    $appearanceCss = '';
    if ($widthCss !== '64rem') {
        $appearanceCss .= ':root{--layout-max-width:'.$widthCss.';}';
    }
    if ($accent) {
        $vars = fn (array $v) => collect($v)->map(fn ($val, $k) => '--'.$k.':'.$val.';')->implode('');
        $appearanceCss .= ':root{'.$vars($accent['light']).'}';
        $appearanceCss .= "@media (prefers-color-scheme: dark){:root:not([data-theme='light']){".$vars($accent['dark']).'}}';
        $appearanceCss .= ":root[data-theme='dark']{".$vars($accent['dark']).'}';
    }

    // DB-backed style theme (ACP visual theme editor): the active theme's compiled CSS (its AA-safe accent +
    // sanitised custom CSS), cached and read once per request. Emitted AFTER the appearance overrides below
    // so an active theme wins on equal specificity.
    $styleThemeCss = app(\App\Theme\StyleThemeManager::class)->css();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-color-mode="{{ $colorMode }}" data-density="{{ $density }}"
      @if ($htmlTheme) data-theme="{{ $htmlTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth <meta name="novfora-auth" content="1"> @endauth
    <title>{{ $title ?? config('app.name', 'NovFora') }}</title>

    {{-- No-flash-of-wrong-theme: apply the stored colour-mode/density BEFORE first paint. nonce-aware for the
         strict CSP (phase-1.5 F-M3); harmless under the baseline policy. --}}
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>
        (function () {
            try {
                var d = document.documentElement;
                var sm = @json($serverColorMode); // signed-in authoritative value, else null (guest)
                var sd = @json($serverDensity);
                var dm = @json($siteDefaultMode); // site-level visitor default (ACP appearance)
                var dd = @json($siteDefaultDensity);
                var mode, den;
                if (sm) { mode = sm; try { localStorage.setItem('novfora-color-mode', sm); } catch (e) {} }
                else { try { mode = localStorage.getItem('novfora-color-mode'); } catch (e) {} if (['auto','light','dark'].indexOf(mode) < 0) mode = dm; }
                if (sd) { den = sd; try { localStorage.setItem('novfora-density', sd); } catch (e) {} }
                else { try { den = localStorage.getItem('novfora-density'); } catch (e) {} if (den !== 'compact' && den !== 'comfortable') den = dd; }
                if (mode === 'light' || mode === 'dark') d.setAttribute('data-theme', mode); else d.removeAttribute('data-theme');
                d.setAttribute('data-color-mode', mode);
                d.setAttribute('data-density', den);
            } catch (e) {}
        })();
    </script>

    {{-- Per-page SEO metadata (canonical, Open Graph, schema.org JSON-LD) is pushed here. --}}
    @stack('head')
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Site Appearance overrides (ACP v1): accent palette (light+dark, AA-safe) + forum width. Emitted
         AFTER the bundle so equal-specificity :root rules win; values are validated hex / a fixed width map. --}}
    @if ($appearanceCss)
        <style @if ($nonce) nonce="{{ $nonce }}" @endif>{!! $appearanceCss !!}</style>
    @endif

    {{-- Active DB-backed style theme (ACP visual theme editor): compiled accent vars + sanitised custom CSS,
         emitted last so an active theme overrides the site Appearance accent. --}}
    @if ($styleThemeCss)
        <style @if ($nonce) nonce="{{ $nonce }}" @endif>{!! $styleThemeCss !!}</style>
    @endif

    {{-- Filesystem child-theme head injection (a theme overrides partials.theme-head). Emitted last so a
         theme's accent palette wins on equal specificity, like the DB style theme above. --}}
    @include('partials.theme-head', ['nonce' => $nonce])
</head>
<body class="min-h-dvh flex flex-col bg-surface text-ink">
    {{-- a11y floor (ADR-0009 §3.3): skip link + a single main landmark. Themes may restyle, not remove. --}}
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="sticky top-0 z-30 border-b border-line bg-surface-raised/85 backdrop-blur">
        <x-ui.container size="lg" class="flex h-14 items-center gap-2 sm:gap-3">
            {{-- Mobile nav toggle --}}
            <div x-data="{ open: false }" class="sm:hidden flex items-center">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="mobile-nav"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
                    <span class="sr-only">Menu</span>
                    <x-ui.icon name="menu" />
                </button>
                {{-- Mobile nav panel --}}
                <div id="mobile-nav" x-show="open" x-cloak x-transition.origin.top.left @click.outside="open = false"
                     class="absolute left-0 right-0 top-14 z-30 border-b border-line bg-surface-raised p-3 shadow-md">
                    <form method="GET" action="{{ route('search.index') }}" role="search" class="mb-2">
                        <label for="m-q" class="sr-only">Search</label>
                        <input id="m-q" type="search" name="q" placeholder="Search…"
                               class="w-full min-h-11 px-3 rounded-md bg-surface border border-line text-ink placeholder:text-ink-subtle">
                    </form>
                    <nav class="flex flex-col" aria-label="Mobile">
                        <a href="{{ route('forums.index') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">Forums</a>
                        @if (\App\Community\MembersDirectory::visibleTo(auth()->user()))
                            <a href="{{ route('members.index') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">Members</a>
                        @endif
                        @auth
                            <a href="{{ route('whats-new') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">What's new</a>
                            <a href="{{ route('notifications.index') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">Notifications</a>
                            <a href="{{ route('pm.inbox') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">Messages</a>
                            <a href="{{ route('settings.profile') }}" class="flex items-center min-h-11 px-3 rounded-md text-ink hover:bg-surface-sunken">Profile &amp; settings</a>
                        @endauth
                    </nav>
                </div>
            </div>

            {{-- Wordmark (text, per the brief; overridable via the Appearance setting). --}}
            <a href="{{ route('forums.index') }}" class="font-bold text-base sm:text-lg tracking-tight text-ink hover:text-accent">{{ $wordmark }}</a>

            {{-- Desktop primary nav --}}
            <nav class="hidden sm:flex items-center gap-0.5" aria-label="Primary">
                <a href="{{ route('forums.index') }}" class="flex items-center min-h-11 px-3 rounded-md text-sm font-medium text-ink-muted hover:text-ink hover:bg-surface-sunken">Forums</a>
                @if (\App\Community\MembersDirectory::visibleTo(auth()->user()))
                    <a href="{{ route('members.index') }}" class="flex items-center min-h-11 px-3 rounded-md text-sm font-medium text-ink-muted hover:text-ink hover:bg-surface-sunken">Members</a>
                @endif
                @auth
                    <a href="{{ route('whats-new') }}" class="flex items-center min-h-11 px-3 rounded-md text-sm font-medium text-ink-muted hover:text-ink hover:bg-surface-sunken">What's new</a>
                @endauth
            </nav>

            {{-- Desktop search --}}
            <form method="GET" action="{{ route('search.index') }}" role="search" class="hidden sm:flex ml-auto w-full max-w-xs">
                <label for="nav-q" class="sr-only">Search</label>
                <div class="relative w-full">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle"><x-ui.icon name="search" class="h-4 w-4" /></span>
                    <input id="nav-q" type="search" name="q" value="{{ request('q') }}" placeholder="Search…"
                           class="w-full min-h-11 pl-9 pr-3 rounded-md bg-surface border border-line text-ink placeholder:text-ink-subtle focus:border-accent">
                </div>
            </form>

            {{-- Right cluster. (Mobile search lives in the hamburger panel, so the bar stays uncrowded at 360px.) --}}
            <div class="flex items-center gap-1 ml-auto sm:ml-1">
                {{-- Colour-mode toggle (auto → light → dark). Works for everyone; persists server-side when signed in. --}}
                <button type="button"
                        x-data="{ mode: document.documentElement.getAttribute('data-color-mode') || 'auto' }"
                        x-on:novfora:color-mode.window="mode = $event.detail"
                        @click="window.NovFora.cycleColorMode()"
                        :aria-label="'Theme: ' + mode + ' (click to change)'" :title="'Theme: ' + mode"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-muted hover:bg-surface-sunken hover:text-ink">
                    <span x-show="mode === 'auto'" @if ($colorMode !== 'auto') x-cloak @endif><x-ui.icon name="monitor" /></span>
                    <span x-show="mode === 'light'" @if ($colorMode !== 'light') x-cloak @endif><x-ui.icon name="sun" /></span>
                    <span x-show="mode === 'dark'" @if ($colorMode !== 'dark') x-cloak @endif><x-ui.icon name="moon" /></span>
                </button>

                @auth
                    <livewire:notification-bell />
                    <livewire:pm.inbox-badge />

                    <x-ui.dropdown align="right" width="w-60">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1.5 min-h-11 pl-1 pr-2 rounded-md hover:bg-surface-sunken">
                                <x-ui.avatar :user="auth()->user()" size="sm" />
                                <span class="hidden sm:block max-w-[8rem] truncate text-sm font-medium text-ink"><x-ui.user-name :user="auth()->user()" /></span>
                                <x-ui.icon name="chevron-down" class="h-4 w-4 text-ink-subtle" />
                            </button>
                        </x-slot:trigger>

                        <div class="px-3 py-2 border-b border-line mb-1">
                            <p class="text-sm font-medium text-ink truncate"><x-ui.user-name :user="auth()->user()" /></p>
                            <p class="text-xs text-ink-muted truncate">{{ '@'.auth()->user()->username }}</p>
                        </div>
                        <x-ui.dropdown-item :href="route('profiles.show', auth()->user())"><x-ui.icon name="user" class="h-4 w-4 text-ink-subtle" /> Profile</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('settings.profile')"><x-ui.icon name="cog" class="h-4 w-4 text-ink-subtle" /> Edit profile</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('settings.appearance')"><x-ui.icon name="sun" class="h-4 w-4 text-ink-subtle" /> Appearance</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('settings.notifications')"><x-ui.icon name="bell" class="h-4 w-4 text-ink-subtle" /> Notifications</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('settings.two-factor')"><x-ui.icon name="shield" class="h-4 w-4 text-ink-subtle" /> Security</x-ui.dropdown-item>
                        @if (auth()->user()->canDo('admin.access', \App\Permissions\Scope::global()))
                            <x-ui.dropdown-item :href="route('admin.dashboard')"><x-ui.icon name="cog" class="h-4 w-4 text-ink-subtle" /> Admin</x-ui.dropdown-item>
                        @endif
                        @if (auth()->user()->isStaff())
                            <x-ui.dropdown-item :href="route('moderation.dashboard')"><x-ui.icon name="shield" class="h-4 w-4 text-ink-subtle" /> Moderation</x-ui.dropdown-item>
                        @endif
                        <div class="border-t border-line mt-1 pt-1">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-ui.dropdown-item type="submit"><x-ui.icon name="logout" class="h-4 w-4 text-ink-subtle" /> Log out</x-ui.dropdown-item>
                            </form>
                        </div>
                    </x-ui.dropdown>
                @else
                    {{-- Mobile shows just "Sign in" (the login page links to register) so the 360px bar never
                         overflows; "Sign up" appears from the sm breakpoint up. The span wrapper carries the
                         responsive display so it isn't fought by the button's base inline-flex. --}}
                    <x-ui.button :href="route('login')" size="sm" variant="ghost">Sign in</x-ui.button>
                    @if (Route::has('register'))
                        <span class="hidden sm:contents"><x-ui.button :href="route('register')" size="sm">Sign up</x-ui.button></span>
                    @endif
                @endauth
            </div>
        </x-ui.container>
    </header>

    {{-- Site-wide notice (ACP v1 General settings) — shown on every page when an admin sets one. --}}
    @if (($site['notice'] ?? '') !== '')
        <div class="border-b border-line bg-accent-soft text-accent-soft-ink">
            <x-ui.container size="lg" class="flex items-start gap-2 py-2.5 text-sm">
                <x-ui.icon name="bell" class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ $site['notice'] }}</p>
            </x-ui.container>
        </div>
    @endif

    {{-- Optional breadcrumb bar: a page provides @section('breadcrumbs') with <x-ui.breadcrumbs>. --}}
    @hasSection('breadcrumbs')
        <div class="border-b border-line bg-surface-raised">
            <x-ui.container size="lg" class="py-2.5">@yield('breadcrumbs')</x-ui.container>
        </div>
    @endif

    {{-- Global flash (session messages). Validation errors are shown inline by forms. --}}
    @if (session('status') || session('success') || session('error'))
        <div class="mx-auto w-full max-w-2xl px-4 sm:px-6 pt-4">
            @if (session('error'))
                <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
            @else
                <x-ui.alert variant="success">{{ session('status') ?? session('success') }}</x-ui.alert>
            @endif
        </div>
    @endif

    <main id="main" class="flex-1 py-6 sm:py-8">@yield('content')</main>

    <footer class="border-t border-line bg-surface-raised">
        <x-ui.container size="xl" class="flex flex-col sm:flex-row items-center justify-between gap-3 py-6 text-sm text-ink-muted">
            <p>@include('partials.footer-tagline')</p>
            {{-- Module UI-slot extension point (ADR-0031): modules may inject sanitised footer widgets here. --}}
            <x-slot-outlet name="footer.widgets" />
            {{-- Density quick-switch (available to everyone; persists server-side when signed in). --}}
            <div x-data="{ d: document.documentElement.getAttribute('data-density') || 'comfortable' }"
                 x-on:novfora:density.window="d = $event.detail"
                 class="inline-flex items-center rounded-md border border-line p-0.5" role="group" aria-label="Display density">
                <button type="button" @click="window.NovFora.setDensity('comfortable')"
                        :class="d === 'comfortable' ? 'bg-accent text-accent-ink' : 'text-ink-muted hover:text-ink'"
                        class="px-3 min-h-9 rounded text-xs font-medium">Comfortable</button>
                <button type="button" @click="window.NovFora.setDensity('compact')"
                        :class="d === 'compact' ? 'bg-accent text-accent-ink' : 'text-ink-muted hover:text-ink'"
                        class="px-3 min-h-9 rounded text-xs font-medium">Compact</button>
            </div>
        </x-ui.container>
    </footer>

    @livewireScripts
</body>
</html>
