<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentRenderer;
use App\Modules\Facades\Hook;
use App\Modules\HookRegistry;
use App\Modules\SlotRegistry;

/*
| ADR-0031 extension primitives: the filter-hook pipeline and the UI-slot registry. The apex pins here are the
| sanitisation contracts — slot output and post.html filter output both pass through the post-HTML allowlist,
| so a module can never smuggle <script>/unsanitised markup onto a page through either seam.
*/

beforeEach(function () {
    app(HookRegistry::class)->flush();
    app(SlotRegistry::class)->flush();
});

it('passes a value through unchanged when no filter is registered', function () {
    expect(Hook::applyFilters('x.value', 'original'))->toBe('original')
        ->and(Hook::hasFilter('x.value'))->toBeFalse();
});

it('applies filters in priority then registration order', function () {
    Hook::addFilter('x.value', fn (string $v) => $v.'-b', priority: 20);
    Hook::addFilter('x.value', fn (string $v) => $v.'-a', priority: 10);
    Hook::addFilter('x.value', fn (string $v) => $v.'-c', priority: 20); // ties break by registration order

    expect(Hook::applyFilters('x.value', 'start'))->toBe('start-a-b-c');
});

it('renders nothing for an unfilled slot', function () {
    expect(app(SlotRegistry::class)->render('footer.widgets'))->toBe('')
        ->and(app(SlotRegistry::class)->has('footer.widgets'))->toBeFalse();
});

it('sanitises slot output so a module cannot inject script', function () {
    app(SlotRegistry::class)->addSlot('footer.widgets', fn () => '<span class="ok">hi</span><script>alert(1)</script>');

    $html = app(SlotRegistry::class)->render('footer.widgets');
    expect($html)->toContain('<span class="ok">hi</span>')
        ->not->toContain('<script>')
        ->not->toContain('alert(1)');
});

it('concatenates multiple slot renderers in priority order', function () {
    app(SlotRegistry::class)->addSlot('footer.widgets', fn () => '<span class="b">B</span>', priority: 20);
    app(SlotRegistry::class)->addSlot('footer.widgets', fn () => '<span class="a">A</span>', priority: 10);

    $html = app(SlotRegistry::class)->render('footer.widgets');
    expect(strpos($html, 'class="a"'))->toBeLessThan(strpos($html, 'class="b"'));
});

it('re-sanitises post.html filter output so a filter cannot inject script', function () {
    Hook::addFilter('post.html', fn (string $html) => $html.'<script>alert(1)</script><span class="injected">x</span>');

    $out = app(ContentRenderer::class)->render('markdown', ['source' => 'hello']);
    expect($out['html'])->toContain('injected')
        ->not->toContain('<script>')
        ->not->toContain('alert(1)');
});
