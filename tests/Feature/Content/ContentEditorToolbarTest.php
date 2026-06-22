<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
| <x-content-editor> toolbar (Pillar 4, Slice 3). Server-render assertions over the restructured toolbar:
| grouped marks + Text-style / Insert menus + emoji + a proper link dialog + a … overflow, every control with
| a visible `title` on top of its `aria-label`, ARIA roving-tabindex hooks, and the FEATURE-DETECTED attach
| control (present only with an attachUrl). The interactive behaviour itself is covered by Dusk (CI).
*/

function renderEditor(string $attrs = ''): string
{
    return html_entity_decode(Blade::render("<x-content-editor {$attrs} />"));
}

it('keeps the ARIA toolbar contract and adds the roving-tabindex controller', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('role="toolbar"')
        ->toContain('aria-label="Formatting"')
        ->toContain('x-data="novforaToolbar()"')
        ->toContain('data-tb-item'); // roving-tabindex items
});

it('exposes grouped marks with visible tooltips on top of aria-labels', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('title="Bold (Ctrl+B)"')->toContain('aria-label="Bold"')
        ->toContain('title="Italic (Ctrl+I)"')->toContain('aria-label="Italic"')
        ->toContain('aria-label="Strikethrough"')
        ->toContain('aria-label="Inline code"');
});

it('adds a Text-style menu exposing paragraph + H1-H3 + quote', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('title="Text style"')
        ->toContain('cmd(\'paragraph\')')
        ->toContain('cmd(\'heading\', { level: 1 })')
        ->toContain('cmd(\'heading\', { level: 2 })')
        ->toContain('cmd(\'heading\', { level: 3 })')
        ->toContain('>Quote<');
});

it('adds an Insert menu exposing table / embed / spoiler / horizontal rule', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('title="Insert"')
        ->toContain('cmd(\'table\')')
        ->toContain('cmd(\'embed\')')
        ->toContain('cmd(\'spoiler\')')
        ->toContain('cmd(\'hr\')');
});

it('adds an emoji picker seeded from the reaction set', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('aria-label="Insert emoji"')
        ->toContain('👍')->toContain('🧠'); // reaction-set emojis
});

it('adds a proper link dialog (input + insert/remove), not a window.prompt', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('id="novfora-link-url"')
        ->toContain('x-model="linkHref"')
        ->toContain('cmd(\'setLink\', { href: linkHref })')
        ->toContain('cmd(\'unsetLink\')');
});

it('provides a … overflow menu for secondary actions', function () {
    expect(renderEditor())->toContain('aria-label="More formatting"');
});

it('feature-detects the attachment endpoint: hidden without attachUrl, shown with it', function () {
    expect(renderEditor())->not->toContain('Attach files');

    $withAttach = renderEditor(':attach-url="\'/forum/attachments\'"');
    expect($withAttach)
        ->toContain('Attach files')
        ->toContain('data-attach-input');
});

it('gates the image control on an upload endpoint', function () {
    expect(renderEditor())->not->toContain('x-ref="file"');

    expect(renderEditor(':upload-url="\'/forum/upload\'"'))
        ->toContain('x-ref="file"')
        ->toContain('>📷 Image<');
});

it('preserves the canonical-sync island + .novfora-prose mount (Dusk contract, no regression)', function () {
    $html = renderEditor();

    expect($html)
        ->toContain('wire:ignore')
        ->toContain('novforaEditor({')
        ->toContain('x-ref="mount"');
});
