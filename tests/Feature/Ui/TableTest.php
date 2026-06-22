<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
| <x-ui.table> — presentational data-table shell (design-system foundation, Slice 1). Sorting + pagination are
| host-driven; this only asserts the chrome: semantic markup pass-through, the density-aware cell padding
| (arbitrary variants that must survive the source(none) + @source build), the a11y caption/region, and the
| dark-mode-correct tokens-only styling.
*/

function renderTable(string $attrs = '', string $head = '<tr><th>Name</th></tr>', string $body = '<tr><td>Ada</td></tr>'): string
{
    // Decode entities: Blade escapes `&` in the class attribute to `&amp;`, but the logical class
    // (`[&_td]:px-3`) is what Tailwind scans from the source and what the browser un-escapes to.
    return html_entity_decode(Blade::render("<x-ui.table {$attrs}><x-slot:head>{$head}</x-slot:head>{$body}</x-ui.table>"));
}

it('renders host head + body markup inside a semantic table', function () {
    $html = renderTable();

    expect($html)
        ->toContain('<table')
        ->toContain('<thead><tr><th>Name</th></tr></thead>')
        ->toContain('<tbody><tr><td>Ada</td></tr></tbody>');
});

it('applies density-aware cell padding via arbitrary variants (must compile under source(none))', function () {
    // Comfortable default: px-3/py-2.5. These arbitrary variants are the spec's compile risk — assert they
    // are emitted onto the class attribute (the build gate confirms they generate real CSS).
    expect(renderTable())
        ->toContain('[&_td]:px-3')
        ->toContain('[&_th]:py-2.5');
});

it('dense prop tightens the cell padding', function () {
    $html = renderTable('dense');

    expect($html)->toContain('[&_td]:px-2.5')->toContain('[&_td]:py-1.5')
        ->and($html)->not->toContain('[&_td]:px-3');
});

it('hover rows are on by default and removable', function () {
    expect(renderTable())->toContain('[&_tbody_tr:hover]:bg-surface-sunken');
    expect(renderTable(':hover="false"'))->not->toContain(':hover]:bg-surface-sunken');
});

it('sticky prop pins the header', function () {
    expect(renderTable())->not->toContain('[&_thead_th]:sticky');
    expect(renderTable('sticky'))->toContain('[&_thead_th]:sticky')->toContain('[&_thead_th]:top-0');
});

it('label adds an sr-only caption and a labelled scroll region', function () {
    $html = renderTable('label="Members"');

    expect($html)
        ->toContain('<caption class="sr-only">Members</caption>')
        ->toContain('role="region"')
        ->toContain('aria-label="Members"');
});

it('the scroll wrapper is keyboard-focusable and horizontally scrollable', function () {
    expect(renderTable())->toContain('tabindex="0"')->toContain('overflow-x-auto');
});

it('is tokens-only (no hard-coded colours)', function () {
    // The component must carry no raw hex/rgb colour — only semantic token utilities.
    expect(renderTable())->not->toMatch('/#[0-9a-fA-F]{3,6}\b/')
        ->and(renderTable())->not->toContain('rgb(');
});
