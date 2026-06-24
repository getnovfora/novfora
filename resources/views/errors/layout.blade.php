{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Standalone error layout: fully self-contained (no @vite, no DB/auth, inline critical CSS on the theme
     palette with a prefers-color-scheme dark block) so it renders even when a 500 broke the app layout or
     assets. Never leaks the exception. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name', 'NovFora') }}</title>
    <style>
        :root { --bg:#f4eee2; --raised:#fcfaf4; --ink:#221c13; --muted:#5c5346; --line:#e6dccb; --accent:#245fbb; --accent-ink:#fff; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b0b10; --raised:#14151d; --ink:#f3e8dd; --muted:#cfc9be; --line:#242230; --accent:#4d93f2; --accent-ink:#08121f; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem;
            background:var(--bg); color:var(--ink); font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
            -webkit-font-smoothing:antialiased; }
        .card { width:100%; max-width:28rem; background:var(--raised); border:1px solid var(--line); border-radius:16px;
            padding:2rem; text-align:center; box-shadow:0 10px 28px -8px rgba(10,6,2,.18); }
        .code { font-size:3rem; font-weight:800; letter-spacing:-.02em; color:var(--accent); margin:0; font-variant-numeric:tabular-nums; }
        h1 { font-size:1.25rem; margin:.5rem 0 .35rem; }
        p.msg { color:var(--muted); margin:0 0 1.25rem; line-height:1.6; }
        a.btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 1.1rem;
            border-radius:10px; background:var(--accent); color:var(--accent-ink); text-decoration:none; font-weight:600; }
        a.btn:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="msg">@yield('message')</p>
        <a class="btn" href="{{ url('/') }}">{{ __('errors.layout.back_home', ['app' => config('app.name', 'NovFora')]) }}</a>
    </main>
</body>
</html>
