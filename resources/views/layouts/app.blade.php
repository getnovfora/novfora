{{-- SPDX-License-Identifier: Apache-2.0 --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Hearth') }}</title>
    {{-- Per-page SEO metadata (canonical, Open Graph, schema.org JSON-LD) is pushed here. --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- a11y floor (ADR-0009 §3.3): skip link + a single main landmark. Themes may restyle, not remove. --}}
    <a href="#main" class="skip-link">Skip to content</a>

    <header style="border-bottom:1px solid var(--hearth-border,#e3e3ea);background:#fff">
        <nav aria-label="Primary" style="max-width:64rem;margin:0 auto;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;padding:.6rem 1rem;font-family:system-ui,sans-serif">
            <a href="{{ route('forums.index') }}" style="font-weight:700;color:var(--hearth-accent,#2d2a6b);text-decoration:none">{{ config('app.name', 'Hearth') }}</a>
            <form method="GET" action="{{ route('search.index') }}" role="search" style="flex:1;min-width:8rem">
                <label for="nav-q" class="sr-only">Search</label>
                <input id="nav-q" type="search" name="q" placeholder="Search…"
                       style="width:100%;box-sizing:border-box;padding:.35rem .6rem;border:1px solid #cfcfd6;border-radius:6px">
            </form>
            @auth
                <a href="{{ route('whats-new') }}" style="color:inherit;text-decoration:none">What's new</a>
                <livewire:notification-bell />
                <a href="{{ route('settings.profile') }}" style="color:inherit;text-decoration:none">{{ auth()->user()->username }}</a>
            @else
                <a href="{{ route('login') }}" style="color:inherit;text-decoration:none">Sign in</a>
            @endauth
        </nav>
    </header>

    <div id="main">@yield('content')</div>
    {{-- Livewire 4 auto-injects its scripts into a full-page response. --}}
</body>
</html>
