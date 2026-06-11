{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The "board offline" page (ACP v1, General settings). Self-contained — no @vite, no layout, no auth —
     inline critical CSS on the theme palette with a prefers-color-scheme dark block, so it renders robustly
     for guests/members while an admin has the board offline. Admins never see it (they pass the gate). No
     auto-refresh: being offline is a deliberate, admin-controlled state, not a transient one. --}}
@php($name = config('app.name', 'NovFora'))
@php($msg = trim((string) ($message ?? '')) !== '' ? $message : 'The board is temporarily offline for maintenance. Please check back soon.')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Offline · {{ $name }}</title>
    <style>
        :root { --bg:#f6f8fc; --raised:#fff; --ink:#141a2b; --muted:#555d72; --line:#e3e7f0; --accent:#4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0d111a; --raised:#161c28; --ink:#e8eaf2; --muted:#9aa3b8; --line:#28303f; --accent:#818cf8; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem;
            background:var(--bg); color:var(--ink); font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
            -webkit-font-smoothing:antialiased; }
        .card { width:100%; max-width:30rem; background:var(--raised); border:1px solid var(--line); border-radius:16px;
            padding:2rem; text-align:center; box-shadow:0 10px 28px -8px rgba(15,23,42,.14); }
        .brand { font-weight:700; letter-spacing:-.01em; color:var(--accent); margin-bottom:1rem; }
        h1 { font-size:1.35rem; margin:.25rem 0 .6rem; letter-spacing:-.01em; }
        p.msg { color:var(--muted); margin:0; line-height:1.6; }
    </style>
</head>
<body>
    <main class="card" role="main">
        <div class="brand">{{ $name }}</div>
        <h1>We’ll be back soon</h1>
        <p class="msg">{{ $msg }}</p>
    </main>
</body>
</html>
