<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
| <x-ui.skeleton> — loading placeholder (design-system foundation, Slice 1). Motion-safe pulse (the global
| prefers-reduced-motion block leaves it static), aria-hidden so screen readers skip the placeholder.
*/

function renderSkeleton(string $attrs = ''): string
{
    return Blade::render("<x-ui.skeleton {$attrs} />");
}

it('renders three shimmer bars by default', function () {
    expect(substr_count(renderSkeleton(), 'animate-pulse'))->toBe(3);
});

it('respects the lines prop', function () {
    expect(substr_count(renderSkeleton('lines="5"'), 'animate-pulse'))->toBe(5);
});

it('never renders fewer than one bar', function () {
    expect(substr_count(renderSkeleton('lines="0"'), 'animate-pulse'))->toBe(1);
});

it('shortens the last bar so it reads as a paragraph tail', function () {
    expect(renderSkeleton('lines="3"'))->toContain('w-2/3');
});

it('uses a motion-safe pulse so reduced-motion leaves it static', function () {
    expect(renderSkeleton())->toContain('motion-safe:animate-pulse');
});

it('is aria-hidden so screen readers skip the placeholder', function () {
    expect(renderSkeleton())->toContain('aria-hidden="true"');
});

it('is tokens-only (no hard-coded colours)', function () {
    expect(renderSkeleton())->not->toMatch('/#[0-9a-fA-F]{3,6}\b/')
        ->and(renderSkeleton())->toContain('bg-surface-sunken');
});
