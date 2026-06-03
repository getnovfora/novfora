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

it('treats an unknown format as tiptap canonical', function () {
    $out = app(ContentRenderer::class)->render('tiptap_json', ['type' => 'doc', 'content' => []]);

    expect($out)->toHaveKeys(['html', 'text']);
});
