<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\CanonicalRenderer;
use App\Content\ContentSanitizer;

/*
| The security boundary (ADR-0005 / security §4): canonical TipTap JSON → safe HTML. Asserts (a) faithful
| structural render of the M2 node set, (b) XSS payloads neutralised, (c) link/media scheme validation,
| (d) heading clamp, (e) canonical JSON stored losslessly. Pure unit — no container, no DB.
*/

function canonRender(array $content): string
{
    return (new CanonicalRenderer(new ContentSanitizer))->toSafeHtml(['type' => 'doc', 'content' => $content]);
}

it('renders the full node set to expected safe HTML', function () {
    $html = canonRender([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => 'struck', 'marks' => [['type' => 'strike']]],
            ['type' => 'text', 'text' => 'under', 'marks' => [['type' => 'underline']]],
            ['type' => 'mention', 'attrs' => ['id' => '1', 'label' => 'alice']],
        ]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
        ]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'quote']]]]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => "echo 'hi';"]]],
        ['type' => 'horizontalRule'],
    ]);

    expect($html)
        ->toContain('<h2>Title</h2>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<s>struck</s>')
        ->toContain('<u>under</u>')
        ->toContain('<span class="mention">')
        ->toContain('<ul>')->toContain('<li>')
        ->toContain('<blockquote>')
        ->toContain('<pre><code>')
        ->toContain('<hr'); // sanitizer self-closes voids → "<hr />"
});

it('renders tables with colspan', function () {
    $html = canonRender([
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'content' => [['type' => 'text', 'text' => 'H']]],
                ['type' => 'tableCell', 'attrs' => ['colspan' => 2], 'content' => [['type' => 'text', 'text' => 'C']]],
            ]],
        ]],
    ]);

    expect($html)->toContain('<table>')->toContain('<tr>')->toContain('<th>H</th>')
        ->toContain('colspan="2"')->toContain('C</td>');
});

it('renders a spoiler as details/summary', function () {
    $html = canonRender([
        ['type' => 'spoiler', 'attrs' => ['summary' => 'Reveal'], 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'secret']]],
        ]],
    ]);

    expect($html)->toContain('<details>')->toContain('<summary>Reveal</summary>')->toContain('secret');
});

it('escapes XSS payloads in text, never emitting a raw dangerous tag', function (string $payload) {
    $html = canonRender([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $payload]]]]);

    foreach (['<script', '<iframe', '<svg', '<img', '<style', '<object', '<embed'] as $tag) {
        expect($html)->not->toContain($tag);
    }
    expect($html)->toContain('&lt;');
})->with([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<svg/onload=alert(1)>',
    '<iframe src=javascript:alert(1)></iframe>',
    '"><script>alert(document.cookie)</script>',
    '<style>body{background:url(javascript:alert(1))}</style>',
    '<a href="javascript:alert(1)">x</a>',
]);

it('drops javascript: and data: links but keeps the text', function (string $href) {
    $html = canonRender([['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
    ]]]);

    expect($html)->not->toContain('<a ')->toContain('click')->not->toContain('<script');
    expect(strtolower($html))->not->toContain('javascript:');
})->with([
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
]);

it('emits a safe link with rel and an escaped href', function () {
    $html = canonRender([['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?a=1&b=2']]]],
    ]]]);

    expect($html)->toContain('href="https://example.com/')
        ->toContain('rel="nofollow noopener noreferrer"')
        ->toContain('>site</a>');
    expect(strtolower($html))->not->toContain('javascript:');
});

it('drops an image with an unsafe src', function () {
    $html = canonRender([['type' => 'paragraph', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'x']],
    ]]]);

    expect($html)->not->toContain('<img');
});

it('clamps heading level to h3', function () {
    $html = canonRender([['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [['type' => 'text', 'text' => 'deep']]]]);

    expect($html)->toContain('<h3>deep</h3>')->not->toContain('<h6');
});

it('round-trips canonical JSON losslessly including multibyte/RTL', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Round trip ✓ 日本語 — RTL: مرحبا']]],
    ]];

    expect(json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true))->toBe($doc);
});

it('projects plain text without markup', function () {
    $text = (new CanonicalRenderer(new ContentSanitizer))->toText(['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Hi']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hey '], ['type' => 'mention', 'attrs' => ['label' => 'bob']]]],
    ]]);

    expect($text)->toContain('Hi')->toContain('@bob')->not->toContain('<');
});
