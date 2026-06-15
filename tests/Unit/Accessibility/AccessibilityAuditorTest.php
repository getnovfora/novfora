<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Accessibility\AccessibilityAuditor;

/*
| Unit coverage for the WCAG auditor ENGINE — proves it flags each violation class and, just as important,
| does NOT flag conformant markup (no false positives that would make the page gate meaningless).
*/

function audit(string $html, bool $full = true): array
{
    return array_map(fn ($f) => $f->rule, (new AccessibilityAuditor)->audit($html, $full));
}

function page(string $body): string
{
    return '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body>'
        .'<a href="#main" class="skip-link">Skip</a><main id="main"><h1>H</h1>'.$body.'</main></body></html>';
}

// ── clean markup → zero findings ─────────────────────────────────────────────────────────────────────────

it('passes a conformant document with no findings', function () {
    expect(audit(page(
        '<img src="a.png" alt="A cat">'
        .'<img src="d.png" alt="">'                                   // decorative, valid
        .'<label for="q">Search</label><input id="q" type="search">'
        .'<label>Email <input type="email"></label>'                  // implicit label
        .'<button type="submit">Go</button>'
        .'<a href="/x">Visit</a>'
        .'<button aria-label="Close"><svg aria-hidden="true"></svg></button>'
    )))->toBe([]);
});

// ── each violation class is caught ───────────────────────────────────────────────────────────────────────

it('flags an image with no alt attribute', function () {
    expect(audit(page('<img src="a.png">')))->toContain('1.1.1');
});

it('flags an unlabelled form control', function () {
    expect(audit(page('<input type="text" name="bare">')))->toContain('4.1.2');
});

it('flags a link with no accessible name', function () {
    expect(audit(page('<a href="/x"><svg aria-hidden="true"></svg></a>')))->toContain('4.1.2');
});

it('flags an icon-only button with no name', function () {
    expect(audit(page('<button><svg aria-hidden="true"></svg></button>')))->toContain('4.1.2');
});

it('flags a positive tabindex', function () {
    expect(audit(page('<a href="/x" tabindex="3">Skip ahead</a>')))->toContain('2.4.3');
});

it('flags a label pointing at a missing id', function () {
    expect(audit(page('<label for="ghost">Name</label>')))->toContain('1.3.1');
});

it('flags aria-labelledby pointing at a missing id', function () {
    expect(audit(page('<div aria-labelledby="nope" role="region">x</div>')))->toContain('4.1.2');
});

it('flags a missing page language', function () {
    $html = '<!DOCTYPE html><html><head><title>T</title></head><body>'
        .'<a href="#m" class="skip-link">s</a><main id="m"><h1>H</h1></main></body></html>';
    expect(audit($html))->toContain('3.1.1');
});

it('flags a missing title, main landmark, h1 and skip link', function () {
    $rules = audit('<!DOCTYPE html><html lang="en"><head></head><body><p>hi</p></body></html>');
    expect($rules)->toContain('2.4.2')   // no title
        ->toContain('1.3.1')             // no main
        ->toContain('2.4.1');            // no skip link
});

it('flags two main landmarks', function () {
    $html = '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body>'
        .'<a href="#m" class="skip-link">s</a><main id="m"><h1>H</h1></main><main>second</main></body></html>';
    expect(audit($html))->toContain('1.3.1');
});

// ── fragment mode skips document-level checks ────────────────────────────────────────────────────────────

it('does not raise document-level findings in fragment mode', function () {
    // A bare labelled control fragment — no html/title/main/skip-link, but fragment mode must not flag those.
    $rules = audit('<label for="q">Q</label><input id="q" type="text">', false);
    expect($rules)->toBe([]);
});

it('still flags element-level issues in fragment mode', function () {
    expect(audit('<img src="x.png">', false))->toBe(['1.1.1']);
});

it('treats hidden and aria-hidden controls as out of scope', function () {
    expect(audit(page('<input type="hidden" name="t"><input type="text" aria-hidden="true">')))->toBe([]);
});
