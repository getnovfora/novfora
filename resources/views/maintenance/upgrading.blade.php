{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- The no-SSH upgrade maintenance page (RH-10). Fully self-contained — no @vite, no layout, no DB/auth,
     inline critical CSS on the theme palette with a prefers-color-scheme dark block — so it renders while
     the schema is mid-migration and never leaks an exception. Auto-refreshes so the browser lands on the
     live site the moment the window closes. When the run is held for the operator (stuck), it shows the
     pre-upgrade backup name + recovery steps instead of the "back shortly" copy. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="{{ (int) ($retryAfter ?? 30) }}">
    <title>{{ ($stuck ?? false) ? 'Upgrade paused' : 'Upgrading' }} · {{ $appName ?? config('app.name', 'Hearth') }}</title>
    <style>
        :root { --bg:#f6f8fc; --raised:#fff; --ink:#141a2b; --muted:#555d72; --line:#e3e7f0; --accent:#4f46e5; --accent-ink:#fff; --warn:#b45309; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0d111a; --raised:#161c28; --ink:#e8eaf2; --muted:#9aa3b8; --line:#28303f; --accent:#818cf8; --accent-ink:#131826; --warn:#fbbf24; } }
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
            <h1>Upgrade paused</h1>
            <p class="msg">
                <span class="warn">{{ $appName ?? config('app.name', 'Hearth') }} started an upgrade that needs a quick hand.</span>
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
                        restore it with <code>php artisan hearth:restore {{ $backup }}</code>. See
                        <strong>getting-started §5</strong> for the full recovery steps.
                    </p>
                @else
                    <p style="margin:0">See <strong>getting-started §5</strong> for the full recovery steps.</p>
                @endif
            </div>
        @else
            <div class="spinner" aria-hidden="true"></div>
            <h1>Just a moment…</h1>
            <p class="msg">
                {{ $appName ?? config('app.name', 'Hearth') }} is applying a quick update. This page refreshes
                itself — you’ll be back in under a minute.
            </p>
        @endif
    </main>
</body>
</html>
