<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Import\BbcodeConverter;

/*
| Property/fuzz test for the importer's BBCode→markdown converter (ADR-0034). It parses UNTRUSTED legacy dump
| bodies, so it must be TOTAL (never throw on arbitrary input), must not catastrophically backtrack on a
| pathological nest, and must leave no residual KNOWN BBCode tag in bracket form (raw HTML is the downstream
| ContentSanitizer's job, not this converter's). Seeded generator → reproducible.
*/

it('is total on arbitrary BBCode-ish input and leaks no known bracket tags', function () {
    $converter = new BbcodeConverter;
    $tokens = [
        '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[url=http://x.test]', '[/url]', '[url]', '[quote]',
        '[/quote]', '[quote="bob"]', '[code]', '[/code]', '[*]', '[list]', '[/list]', '[img]http://x[/img]',
        '[b:abc]', '[/b:abc]', 'plain text ', " \n ", '&amp;', '<not-bbcode>', '[unknown]', '[/unknown]', '=]', '[',
    ];

    mt_srand(20260613);
    for ($i = 0; $i < 600; $i++) {
        $len = mt_rand(0, 50);
        $input = '';
        for ($j = 0; $j < $len; $j++) {
            $input .= $tokens[mt_rand(0, count($tokens) - 1)];
        }
        $out = $converter->toMarkdown($input, mt_rand(0, 1) ? 'abc' : '');

        // No KNOWN BBCode tag survives in bracket form (converted or stripped).
        foreach (['[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[quote]', '[/quote]', '[code]', '[/code]', '[list]', '[/list]', '[url'] as $tag) {
            expect($out)->not->toContain($tag);
        }
    }

    // Explicit conversions + a deep nest that would expose catastrophic regex backtracking if present.
    expect($converter->toMarkdown('[b]x[/b]'))->toBe('**x**')
        ->and($converter->toMarkdown('[url=http://x.test]link[/url]'))->toBe('[link](http://x.test)')
        ->and($converter->toMarkdown('[b:u]hi[/b:u]', 'u'))->toBe('**hi**')
        ->and($converter->toMarkdown(str_repeat('[b]', 2000).'x'.str_repeat('[/b]', 2000)))->toBeString();
});
