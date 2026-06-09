<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\Oembed\EmbedPolicy;

/*
| The dedicated embed policy (amendment #2): allowlist matching, the single sandboxed iframe built from a
| constructed (allowlisted-host) player URL, and the link-card facade for everything else.
*/

beforeEach(fn () => $this->policy = new EmbedPolicy);

it('matches allowlisted YouTube URL forms to the youtube-nocookie embed', function (string $url) {
    $m = $this->policy->match($url);
    expect($m)->not->toBeNull()
        ->and($m['provider'])->toBe('youtube')
        ->and($m['src'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($m['host'])->toBe('www.youtube-nocookie.com');
})->with([
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ&t=10s',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
]);

it('matches a Vimeo URL to the player.vimeo embed', function () {
    $m = $this->policy->match('https://vimeo.com/123456789');
    expect($m['provider'])->toBe('vimeo')->and($m['src'])->toBe('https://player.vimeo.com/video/123456789');
});

it('does not match a non-allowlisted URL', function (string $url) {
    expect($this->policy->match($url))->toBeNull();
})->with([
    'https://evil.example/watch?v=dQw4w9WgXcQ',
    'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
    'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
    'https://example.com/article',
]);

it('builds a single sandboxed iframe on the allowlisted host', function () {
    $html = $this->policy->iframe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'A Title');
    expect($html)->toContain('<iframe ')
        ->toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"')
        ->toContain('sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"')
        ->toContain('allow="')
        ->toContain('loading="lazy"')
        ->toContain('title="A Title"')
        ->and(substr_count($html, '<iframe'))->toBe(1);
});

it('escapes the iframe title (no attribute breakout)', function () {
    $html = $this->policy->iframe('https://player.vimeo.com/video/1', '"><script>alert(1)</script>');
    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;');
});

it('escapes the iframe src (no attribute breakout from a hostile src)', function () {
    // iframe() is public and takes an arbitrary string; the constructed-src pipeline never feeds it a hostile
    // value, but the esc($src) call is defence-in-depth and must stay enforced.
    $html = $this->policy->iframe('https://h/"><script>alert(1)</script>');
    expect($html)->not->toContain('"><script>')
        ->toContain('src="https://h/&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"');
});

it('escapes the iframe allow + sandbox attributes from a hostile config value', function () {
    // allow/sandbox come from server config (config/hearth.php). A typo or a compromised config must not be
    // able to break out of the attribute — esc() is the guard and this pins it.
    config([
        'hearth.oembed.allow' => '"><script>a()</script>',
        'hearth.oembed.sandbox' => '"><img src=x onerror=b()>',
    ]);
    $html = (new EmbedPolicy)->iframe('https://www.youtube-nocookie.com/embed/x');
    expect($html)->not->toContain('"><script>')
        ->not->toContain('"><img')
        ->toContain('allow="&quot;&gt;&lt;script&gt;a()&lt;/script&gt;"')
        ->toContain('sandbox="&quot;&gt;&lt;img src=x onerror=b()&gt;"');
});

it('builds a safe link-card facade for an arbitrary URL', function () {
    $html = $this->policy->facade('https://random.example/post/42', 'Cool post');
    expect($html)->toContain('hearth-embed-facade')
        ->toContain('href="https://random.example/post/42"')
        ->toContain('rel="nofollow noopener noreferrer"')
        ->toContain('Cool post')
        ->not->toContain('<iframe');
});

it('renders nothing for a non-http(s) facade URL', function () {
    expect($this->policy->facade('javascript:alert(1)'))->toBe('')
        ->and($this->policy->facade('data:text/html,<script>'))->toBe('');
});
