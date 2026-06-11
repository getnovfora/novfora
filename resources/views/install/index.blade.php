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
    <title>Install {{ config('app.name', 'NovFora') }}</title>
    {{-- Fully self-contained: the installer must NEVER depend on the app CSS bundle (a fresh upload may not
         have built assets, and the bundle's hashes change with the theme). All styling is the inline <style>
         below, on the same indigo/slate palette as the theme. --}}
    {{-- This is a STANDALONE pre-install layout: it can't use layouts/app (which assumes a DB + auth), so
         it must declare Livewire's runtime itself. Emit @livewireStyles/@livewireScripts EXPLICITLY instead
         of leaning on Livewire's response-rewrite auto-injection — on real shared hosts (cPanel/LiteSpeed/
         Cloudflare) the optimizer/page-cache layer can strip or defer post-render-injected assets, which
         left the wizard rendered but its wire:click/wire:model dead (RH-6). FrontendAssets' render-guards
         keep auto-injection from double-injecting these. --}}
    @livewireStyles
    <style>
        :root { --ink:#141a2b; --muted:#555d72; --line:#e3e7f0; --accent:#4f46e5; --ok:#15803d; --warn:#b45309; --bad:#dc2626; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: var(--ink); background:#f6f8fc; margin:0; }
        .wrap { max-width: 44rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:1.5rem; }
        h1 { margin:.2rem 0 .25rem; font-size:1.5rem; }
        a { color: var(--accent); }
        .muted { color: var(--muted); }
        .steps { display:flex; flex-wrap:wrap; gap:.4rem; list-style:none; padding:0; margin:1.25rem 0; font-size:.82rem; }
        .steps li { padding:.25rem .6rem; border:1px solid var(--line); border-radius:999px; color:var(--muted); }
        .steps li[aria-current=step] { border-color:var(--accent); color:var(--accent); font-weight:600; }
        label { display:block; font-weight:600; margin:.9rem 0 .25rem; }
        input[type=text], input[type=url], input[type=email], input[type=password], input[type=number], select {
            width:100%; box-sizing:border-box; padding:.5rem .6rem; border:1px solid #cdd3e1; border-radius:8px; font-size:1rem; }
        input:focus, select:focus { outline:2px solid var(--accent); outline-offset:1px; border-color:var(--accent); }
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
        .note { background:#eef2ff; border:1px solid #e0e7ff; border-radius:8px; padding:.6rem .8rem; margin:.6rem 0; font-size:.9rem; }
        table.kv { width:100%; border-collapse:collapse; font-size:.9rem; }
        table.kv td { padding:.3rem .5rem; border-bottom:1px solid var(--line); }
        code { background:#eef1f7; padding:.1rem .35rem; border-radius:4px; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>{{ config('app.name', 'NovFora') }} installer</h1>
            <p class="muted">Set up your community in a few steps — no SSH or command line required.</p>
        </header>
        <livewire:installer.wizard />
    </div>

    @livewireScripts

    {{-- Boot resilience (RH-6, the actual fix). Livewire's bundle auto-starts ONLY from a DOMContentLoaded
         event listener (no readyState fallback): it sets window.Alpine.__fromLivewire synchronously but
         calls Livewire.start() — which builds $wire and binds every wire: directive — only when that event
         fires. A shared-host JS optimizer (cPanel/LiteSpeed/Cloudflare) that defers this bundle so it runs
         AFTER DOMContentLoaded already fired leaves the listener dangling: Alpine is present but start()
         never runs, so the wizard's Continue/Back/model bindings are dead with no console error — exactly
         the real-host symptom. If Livewire has loaded but hasn't started by the time the window finishes
         loading, start it once. The livewire:init flag makes this a no-op on the normal (on-time) path, so
         it can never double-start. The data-* attributes ask the common optimizers to leave THIS guard
         alone so it can do its job. --}}
    <script data-no-optimize="1" data-no-defer="1" data-cfasync="false"@if ($cspNonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $cspNonce }}"@endif>
        (function () {
            var started = false;
            document.addEventListener('livewire:init', function () { started = true; });
            function ensureLivewireStarted() {
                if (started) return;
                if (! window.Livewire || typeof window.Livewire.start !== 'function') return;
                started = true;
                try { window.Livewire.start(); } catch (e) { /* already running — nothing to do */ }
            }
            if (document.readyState === 'complete') {
                ensureLivewireStarted();
            } else {
                window.addEventListener('load', ensureLivewireStarted);
            }
        })();
    </script>
</body>
</html>
