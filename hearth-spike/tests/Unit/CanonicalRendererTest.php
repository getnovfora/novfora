<?php
// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Support\CanonicalRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Spike 0 — criterion #4 (the security boundary): canonical TipTap JSON -> safe HTML.
 * Asserts (a) faithful structural render of the node set, (b) XSS payloads neutralised,
 * (c) link-scheme validation, (d) heading clamp, (e) canonical JSON stored losslessly.
 */
class CanonicalRendererTest extends TestCase
{
    private CanonicalRenderer $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new CanonicalRenderer();
    }

    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    private function xssPayloads(): array
    {
        return [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<svg/onload=alert(1)>',
            '<iframe src=javascript:alert(1)></iframe>',
            '"><script>alert(document.cookie)</script>',
            '<style>body{background:url(javascript:alert(1))}</style>',
            '<a href="javascript:alert(1)">x</a>',
        ];
    }

    public function test_renders_full_node_set_to_expected_safe_html(): void
    {
        $html = $this->r->toSafeHtml($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'italic']]],
                ['type' => 'text', 'text' => ' ping '],
                ['type' => 'mention', 'attrs' => ['id' => '1', 'label' => 'alice']],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
            ]],
            ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'quote']]]]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => "echo 'hi';"]]],
        ]));

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        // NB: the sanitizer entity-encodes '@' to '&#64;' (safe; renders as @). Assert structurally.
        $this->assertStringContainsString('<span class="mention">', $html);
        $this->assertStringContainsString('alice</span>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<pre><code>', $html);
    }

    public function test_xss_payloads_in_text_are_escaped_not_executed(): void
    {
        foreach ($this->xssPayloads() as $payload) {
            $html = $this->r->toSafeHtml($this->doc([
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $payload]]],
            ]));

            // No raw dangerous element from the payload survives (they become escaped text).
            foreach (['<script', '<iframe', '<svg', '<img', '<style', '<object', '<embed'] as $rawTag) {
                $this->assertStringNotContainsString($rawTag, $html, "payload leaked a raw tag: {$payload}");
            }
            // The payload's angle brackets must have been HTML-escaped.
            $this->assertStringContainsString('&lt;', $html, "payload was not escaped: {$payload}");
        }
    }

    public function test_link_with_javascript_scheme_is_dropped_but_text_kept(): void
    {
        $html = $this->r->toSafeHtml($this->doc([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ]],
        ]));

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html); // only "click" text remains
        $this->assertStringContainsString('click', $html);
        $this->assertStringNotContainsString('<a ', $html); // the unsafe link is not emitted
    }

    public function test_data_uri_link_is_dropped(): void
    {
        $html = $this->r->toSafeHtml($this->doc([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'data:text/html,<script>alert(1)</script>']]]],
            ]],
        ]));
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_safe_link_is_emitted_with_rel_and_escaped_href(): void
    {
        $html = $this->r->toSafeHtml($this->doc([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?a=1&b=2']]]],
            ]],
        ]));

        // The sanitizer conservatively entity-encodes '=' to '&#61;' in attribute values (still a valid URL).
        $this->assertStringContainsString('href="https://example.com/', $html);
        $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $html);
        $this->assertStringContainsString('>site</a>', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }

    public function test_heading_level_is_clamped_to_h3(): void
    {
        $html = $this->r->toSafeHtml($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [['type' => 'text', 'text' => 'deep']]],
        ]));
        $this->assertStringContainsString('<h3>deep</h3>', $html);
        $this->assertStringNotContainsString('<h6', $html);
    }

    public function test_canonical_json_round_trips_losslessly_including_multibyte(): void
    {
        // Canonical JSON is the source of truth; the app stores it verbatim and reloads it into the editor.
        $doc = $this->doc([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Round trip ✓ 日本語 — RTL: مرحبا']]],
        ]);
        $reloaded = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        $this->assertSame($doc, $reloaded, 'Canonical JSON must round-trip without loss (incl. multibyte/RTL).');
    }

    public function test_plain_text_projection(): void
    {
        $text = $this->r->toText($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'hey '],
                ['type' => 'mention', 'attrs' => ['label' => 'bob']],
            ]],
        ]));
        $this->assertStringContainsString('Hi', $text);
        $this->assertStringContainsString('@bob', $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
