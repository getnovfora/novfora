<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentRenderer;

/*
| ContentRenderer dispatches by body_format and funnels every path through the same allowlist sanitizer.
| The Markdown input mode must be just as XSS-safe as the TipTap path.
*/

it('renders tiptap canonical to sanitized HTML + text', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi there']]]],
    ]);

    expect($out['html'])->toContain('<p>hi there</p>');
    expect($out['text'])->toContain('hi there');
});

it('renders markdown to sanitized HTML, escaping embedded raw HTML and unsafe links', function () {
    $out = app(ContentRenderer::class)->render('markdown', [
        'source' => "# Title\n\nHello **bold** <script>alert(1)</script>\n\n[x](javascript:alert(1))",
    ]);

    expect($out['html'])->toContain('<h1>')->toContain('<strong>bold</strong>');
    expect($out['html'])->not->toContain('<script');
    expect(strtolower($out['html']))->not->toContain('javascript:');
    expect($out['text'])->toContain('Title');
});

it('renders a spoiler / content-warning block to a sanitized <details>/<summary> (member tool 2.3)', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', [
        'type' => 'doc',
        'content' => [[
            'type' => 'spoiler',
            'attrs' => ['summary' => 'Ending'],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'the butler did it']]]],
        ]],
    ]);

    expect($out['html'])->toContain('<details>')
        ->toContain('<summary>Ending</summary>')
        ->toContain('the butler did it');
    expect($out['text'])->toContain('the butler did it'); // text projection still extracts the inner text
});

it('escapes a spoiler summary and sanitises its body (no XSS through a content warning)', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', [
        'type' => 'doc',
        'content' => [[
            'type' => 'spoiler',
            'attrs' => ['summary' => '<script>alert(1)</script>'],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'safe body']]]],
        ]],
    ]);

    expect($out['html'])->not->toContain('<script')->toContain('safe body');
});

it('treats an unknown format as tiptap canonical', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', ['type' => 'doc', 'content' => []]);

    expect($out)->toHaveKeys(['html', 'text']);
});

it('renders a non-image attachment as a safe file card (ADR-0094)', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', [
        'type' => 'doc',
        'content' => [
            ['type' => 'file', 'attrs' => ['src' => '/attachments/42', 'name' => 'report.pdf']],
        ],
    ]);

    // A span[class] card wrapping a plain link — survives the sanitizer; the serve route forces download.
    expect($out['html'])
        ->toContain('novfora-file')
        ->toContain('href="/attachments/42"')
        ->toContain('report.pdf');
});

it('drops a file card with an unsafe (javascript:) src', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', [
        'type' => 'doc',
        'content' => [
            ['type' => 'file', 'attrs' => ['src' => 'javascript:alert(1)', 'name' => 'evil']],
        ],
    ]);

    expect(strtolower($out['html']))->not->toContain('javascript:');
});
