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
    @yield('content')
    {{-- Livewire 4 auto-injects its scripts into a full-page response. --}}
</body>
</html>
