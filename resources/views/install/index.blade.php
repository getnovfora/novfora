{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Standalone installer chrome. Deliberately does NOT extend layouts/app: before install there is no
     database, so the app nav's auth()/route() calls would fail. Self-contained, minimal, mobile-first. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Install {{ config('app.name', 'Hearth') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        :root { --ink:#1f2430; --muted:#5b6472; --line:#e3e3ea; --accent:#2d2a6b; --ok:#1a7f4b; --warn:#9a6b00; --bad:#b3261e; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: var(--ink); background:#f6f6f9; margin:0; }
        .wrap { max-width: 44rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:1.5rem; }
        h1 { margin:.2rem 0 .25rem; font-size:1.5rem; }
        a { color: var(--accent); }
        .muted { color: var(--muted); }
        .steps { display:flex; flex-wrap:wrap; gap:.4rem; list-style:none; padding:0; margin:1.25rem 0; font-size:.82rem; }
        .steps li { padding:.25rem .6rem; border:1px solid var(--line); border-radius:999px; color:var(--muted); }
        .steps li[aria-current=step] { border-color:var(--accent); color:var(--accent); font-weight:600; }
        label { display:block; font-weight:600; margin:.9rem 0 .25rem; }
        input[type=text], input[type=url], input[type=email], input[type=password], input[type=number], select {
            width:100%; box-sizing:border-box; padding:.5rem .6rem; border:1px solid #c8c8d2; border-radius:8px; font-size:1rem; }
        .row { display:flex; gap:.8rem; flex-wrap:wrap; }
        .row > div { flex:1; min-width:9rem; }
        .btn { display:inline-block; padding:.55rem 1.1rem; border-radius:8px; border:1px solid var(--accent); background:var(--accent); color:#fff; font-size:1rem; cursor:pointer; }
        .btn[disabled] { opacity:.5; cursor:not-allowed; }
        .btn-ghost { background:#fff; color:var(--accent); }
        .actions { display:flex; justify-content:space-between; margin-top:1.5rem; gap:.5rem; }
        .check { display:flex; gap:.6rem; padding:.4rem 0; border-bottom:1px dashed var(--line); }
        .badge { flex:none; width:1.3rem; text-align:center; font-weight:700; }
        .pass { color:var(--ok); } .warn { color:var(--warn); } .fail { color:var(--bad); }
        .err { color:var(--bad); font-size:.85rem; margin-top:.25rem; }
        .note { background:#f0f4ff; border:1px solid #d6e0ff; border-radius:8px; padding:.6rem .8rem; margin:.6rem 0; font-size:.9rem; }
        table.kv { width:100%; border-collapse:collapse; font-size:.9rem; }
        table.kv td { padding:.3rem .5rem; border-bottom:1px solid var(--line); }
        code { background:#f1f1f4; padding:.1rem .35rem; border-radius:4px; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>{{ config('app.name', 'Hearth') }} installer</h1>
            <p class="muted">Set up your community in a few steps — no SSH or command line required.</p>
        </header>
        <livewire:installer.wizard />
    </div>
</body>
</html>
