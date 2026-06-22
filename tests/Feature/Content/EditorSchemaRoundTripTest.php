<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentRenderer;

/*
| Slice 3 exposes schema the renderer ALREADY supported but the toolbar didn't surface — H1/H3, table, and
| the horizontal rule. These lock the canonical → sanitized-HTML round-trip for those node types, so the
| newly-exposed toolbar controls are guaranteed to render (and stay rendering) on the server.
*/

function renderCanonical(array $doc): string
{
    return app(ContentRenderer::class)->render('tiptap_json', array_merge(['type' => 'doc'], $doc))['html'];
}

it('round-trips H1 and H3 headings (now exposed in the Text-style menu)', function () {
    $html = renderCanonical(['content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Big']]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Small']]],
    ]]);

    expect($html)->toContain('<h1>Big</h1>')->toContain('<h3>Small</h3>');
});

it('clamps an out-of-range heading level to the rendered schema (no h4+)', function () {
    $html = renderCanonical(['content' => [
        ['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [['type' => 'text', 'text' => 'X']]],
    ]]);

    expect($html)->not->toContain('<h6')->not->toContain('<h4');
});

it('round-trips a table (now exposed in the Insert menu)', function () {
    $html = renderCanonical(['content' => [
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'H']]]]],
            ]],
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'C']]]]],
            ]],
        ]],
    ]]);

    expect($html)
        ->toContain('<table>')
        ->toContain('<th>')->toContain('H')
        ->toContain('<td>')->toContain('C');
});

it('round-trips a horizontal rule (now exposed in the Insert menu)', function () {
    // The sanitizer normalises the void element to `<hr />`; match either form.
    expect(renderCanonical(['content' => [['type' => 'horizontalRule']]]))->toContain('<hr');
});
