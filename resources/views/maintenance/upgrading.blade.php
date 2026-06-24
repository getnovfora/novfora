{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The no-SSH operability maintenance page. Covers a no-SSH UPGRADE (RH-10) and a no-SSH panel RESTORE
     (RH-11) via $mode ('upgrade' | 'restore'). Fully self-contained — no @vite, no layout, no DB/auth,
     inline critical CSS on the theme palette with a prefers-color-scheme dark block — so it renders while
     the database is mid-migration OR mid-restore and never leaks an exception. Auto-refreshes so the
     browser lands on the live site the moment the window closes. When a run is held for the operator
     (stuck), it shows recovery steps + the relevant backup name instead of the "back shortly" copy. --}}
@php($mode = ($mode ?? 'upgrade') === 'restore' ? 'restore' : 'upgrade')
@php($name = $appName ?? config('app.name', 'NovFora'))
@php($title = ($stuck ?? false) ? ($mode === 'restore' ? 'Restore paused' : 'Upgrade paused') : ($mode === 'restore' ? 'Restoring' : 'Upgrading'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="{{ (int) ($retryAfter ?? 30) }}">
    <title>{{ $title }} · {{ $name }}</title>
    <style>
        :root { --bg:#f4eee2; --raised:#fcfaf4; --ink:#221c13; --muted:#5c5346; --line:#e6dccb; --accent:#245fbb; --accent-ink:#fff; --warn:#8f6207; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b0b10; --raised:#14151d; --ink:#f3e8dd; --muted:#cfc9be; --line:#242230; --accent:#4d93f2; --accent-ink:#08121f; --warn:#e0ae3f; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem;
            background:var(--bg); color:var(--ink); font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
            -webkit-font-smoothing:antialiased; }
        .card { width:100%; max-width:30rem; background:var(--raised); border:1px solid var(--line); border-radius:16px;
            padding:2rem; text-align:center; box-shadow:0 10px 28px -8px rgba(15,23,42,.14); }
        .spinner { width:38px; height:38px; margin:0 auto 1rem; border:3px solid var(--line); border-top-color:var(--accent);
            border-radius:50%; animation:spin 0.9s linear infinite; }
        @media (prefers-reduced-motion: reduce) { .spinner { animation:none; } }
        @keyframes spin { to { transform:rotate(360deg); } }
        h1 { font-size:1.35rem; margin:.25rem 0 .5rem; letter-spacing:-.01em; }
        p.msg { color:var(--muted); margin:0 0 1rem; line-height:1.6; }
        .hint { margin-top:1.25rem; padding:1rem; border:1px solid var(--line); border-radius:10px; background:var(--bg);
            text-align:left; font-size:.875rem; color:var(--muted); line-height:1.6; }
        .hint strong { color:var(--ink); }
        .hint code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.8125rem;
            background:var(--raised); border:1px solid var(--line); border-radius:5px; padding:.05rem .35rem; color:var(--ink); }
        .warn { color:var(--warn); font-weight:600; }
    </style>
</head>
<body>
    <main class="card" role="main">
        @if ($stuck ?? false)
            @if ($mode === 'restore')
                <h1>Restore paused</h1>
                <p class="msg">
                    <span class="warn">{{ $name }} could not finish restoring a backup.</span>
                    The site is safely held in maintenance so a partly-restored database is never served.
                </p>
                <div class="hint">
                    <p style="margin:0 0 .6rem"><strong>To recover without SSH:</strong></p>
                    <p style="margin:0 0 .6rem">
                        Over FTP / your host file manager, delete <code>storage/novfora-restore.json</code> to
                        lift this hold, then sign in and restore a known-good backup again from
                        <strong>Admin → System → Backups</strong>
                        @if (! empty($backup))
                            — including the pre-restore safety snapshot <code>{{ $backup }}</code> taken just
                            before this attempt, which returns you to where you were.
                        @else
                            .
                        @endif
                    </p>
                    <p style="margin:0">
                        With shell access you can instead restore directly:
                        <code>php artisan novfora:restore &lt;archive&gt;</code>. See
                        <strong><code>docs/getting-started.md</code> §5</strong> for the full recovery steps.
                    </p>
                </div>
            @else
                <h1>Upgrade paused</h1>
                <p class="msg">
                    <span class="warn">{{ $name }} started an upgrade that needs a quick hand.</span>
                    The site is safely held in maintenance — no data was lost.
                </p>
                <div class="hint">
                    <p style="margin:0 0 .6rem"><strong>To recover (no SSH needed):</strong></p>
                    <p style="margin:0 0 .6rem">
                        Re-upload the <strong>previous</strong> release over this install. The code will match the
                        database again and the site comes back on its own within a minute.
                    </p>
                    @if (! empty($backup))
                        <p style="margin:0">
                            A pre-upgrade backup was taken first: <code>{{ $backup }}</code>. With shell access you can
                            restore it with <code>php artisan novfora:restore {{ $backup }}</code>. See
                            <strong><code>docs/getting-started.md</code> §5</strong> for the full recovery steps.
                        </p>
                    @else
                        <p style="margin:0">See <strong><code>docs/getting-started.md</code> §5</strong> for the full recovery steps.</p>
                    @endif
                </div>
            @endif
        @else
            <div class="spinner" aria-hidden="true"></div>
            <h1>Just a moment…</h1>
            <p class="msg">
                @if ($mode === 'restore')
                    {{ $name }} is restoring a backup. This page refreshes itself — you’ll be back in under a minute.
                @else
                    {{ $name }} is applying a quick update. This page refreshes itself — you’ll be back in under a minute.
                @endif
            </p>
        @endif
    </main>
</body>
</html>
