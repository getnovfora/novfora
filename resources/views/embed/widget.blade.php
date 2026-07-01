{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- U7 (ADR-0103): a self-contained embed document — no app layout, no session, ZERO scripts. Everything
     rendered here is guest-visible and Blade-escaped; styles are inline so the CSP can stay default-src 'none'.
     data-theme: light | dark | auto (auto follows the embedding user's prefers-color-scheme). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $data['title'] }} · {{ $siteName }}</title>
    <style>
        :root { --nvf-surface:#fcfaf4; --nvf-ink:#1c1b18; --nvf-ink-muted:#5d5a52; --nvf-line:#e3ded2; --nvf-accent:#1d4ed8; }
        html[data-theme="dark"] { --nvf-surface:#0b0b10; --nvf-ink:#ece9e2; --nvf-ink-muted:#a3a09a; --nvf-line:#2a2a33; --nvf-accent:#7ca5f5; }
        @media (prefers-color-scheme: dark) {
            html[data-theme="auto"] { --nvf-surface:#0b0b10; --nvf-ink:#ece9e2; --nvf-ink-muted:#a3a09a; --nvf-line:#2a2a33; --nvf-accent:#7ca5f5; }
        }
        * { box-sizing: border-box; }
        body { margin:0; padding:12px; background:var(--nvf-surface); color:var(--nvf-ink);
               font:14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color:var(--nvf-accent); text-decoration:none; }
        a:hover { text-decoration:underline; }
        a:focus-visible { outline:2px solid var(--nvf-accent); outline-offset:2px; border-radius:2px; }
        header { display:flex; align-items:baseline; justify-content:space-between; gap:8px; margin-bottom:8px; }
        h1 { font-size:15px; margin:0; }
        ul { list-style:none; margin:0; padding:0; }
        li { padding:6px 0; border-top:1px solid var(--nvf-line); }
        li:first-child { border-top:0; }
        time, .muted { color:var(--nvf-ink-muted); font-size:12px; }
        dl { display:flex; gap:20px; margin:0; }
        dt { color:var(--nvf-ink-muted); font-size:12px; }
        dd { margin:0; font-size:18px; font-weight:600; }
        footer { margin-top:10px; padding-top:8px; border-top:1px solid var(--nvf-line); font-size:11px; }
        footer a { color:var(--nvf-ink-muted); }
        .skip { position:absolute; left:-9999px; top:0; background:var(--nvf-surface); padding:4px 8px; }
        .skip:focus { left:8px; }
    </style>
</head>
<body>
    <a class="skip" href="#nvf-content">{{ __('Skip to content') }}</a>
    <header>
        <h1><a href="{{ $data['url'] }}" target="_blank" rel="noopener">{{ $data['title'] }}</a></h1>
    </header>

    <main id="nvf-content">
    @if ($widget === 'topics')
        @if ($data['items'] === [])
            <p class="muted">{{ __('No topics yet.') }}</p>
        @else
            <ul>
                @foreach ($data['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener">{{ $item['title'] }}</a>
                        @if ($item['posted_at'])
                            <div><time datetime="{{ $item['posted_at'] }}">{{ \Illuminate\Support\Carbon::parse($item['posted_at'])->diffForHumans() }}</time></div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @elseif ($widget === 'stats')
        <dl>
            <div><dt>{{ __('Members') }}</dt><dd>{{ number_format($data['members']) }}</dd></div>
            <div><dt>{{ __('Topics') }}</dt><dd>{{ number_format($data['topics']) }}</dd></div>
            <div><dt>{{ __('Posts') }}</dt><dd>{{ number_format($data['posts']) }}</dd></div>
        </dl>
    @endif
    </main>

    <footer><a href="{{ $data['url'] }}" target="_blank" rel="noopener">{{ __('Powered by :name', ['name' => $siteName]) }}</a></footer>
</body>
</html>
