<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\RevisionDiffService;

/*
| Format-aware edit-history diff (P2-M1, amendment #3). The crux: a tiptap diff must reflect FORMATTING /
| LINK / IMAGE edits, which the tags-stripped body_text search projection would hide. The extraction keeps
| markdown-like markers so those edits surface; the diff is a dependency-free LCS over the lines.
*/

beforeEach(function () {
    $this->differ = new RevisionDiffService;
});

function para(array $content): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
}

function txt(string $t, array $marks = []): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $t] : ['type' => 'text', 'text' => $t, 'marks' => $marks];
}

it('diffs markdown source line by line', function () {
    $lines = $this->differ->diff('markdown', ['source' => "line one\nline two"], 'markdown', ['source' => "line one\nline TWO changed"]);
    $types = array_column($lines, 'type');

    expect($types)->toContain('same')->toContain('del')->toContain('add');
});

it('shows a formatting-only edit in a tiptap diff (body_text would hide it — amendment #3)', function () {
    $old = para([txt('hello')]);
    $new = para([txt('hello', [['type' => 'bold']])]);

    // The extraction itself differs even though the plain text is identical.
    expect($this->differ->extract('tiptap_json', $old))->toBe('hello')
        ->and($this->differ->extract('tiptap_json', $new))->toBe('**hello**');

    $byType = collect($this->differ->diff('tiptap_json', $old, 'tiptap_json', $new))
        ->groupBy('type')->map(fn ($g) => $g->pluck('text')->all());

    expect($byType['del'] ?? [])->toContain('hello')
        ->and($byType['add'] ?? [])->toContain('**hello**');
});

it('shows a link href change in the diff', function () {
    $old = para([txt('site', [['type' => 'link', 'attrs' => ['href' => 'https://old.test']]])]);
    $new = para([txt('site', [['type' => 'link', 'attrs' => ['href' => 'https://new.test']]])]);

    expect($this->differ->extract('tiptap_json', $old))->toBe('[site](https://old.test)')
        ->and($this->differ->extract('tiptap_json', $new))->toBe('[site](https://new.test)');
    expect(array_column($this->differ->diff('tiptap_json', $old, 'tiptap_json', $new), 'type'))->toContain('add')->toContain('del');
});

it('shows an image src change in the diff', function () {
    $old = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png', 'alt' => 'a']]]];
    $new = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://x.test/b.png', 'alt' => 'a']]]];

    expect($this->differ->extract('tiptap_json', $old))->toBe('![a](https://x.test/a.png)');
    expect(array_column($this->differ->diff('tiptap_json', $old, 'tiptap_json', $new), 'type'))->toContain('add');
});

it('preserves headings and list structure in the extraction', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [txt('Title')]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [txt('first')]]]],
        ]],
    ]];

    expect($this->differ->extract('tiptap_json', $doc))->toBe("## Title\n- first");
});

it('reports all-same lines for identical content', function () {
    $doc = para([txt('unchanged')]);

    expect(collect($this->differ->diff('tiptap_json', $doc, 'tiptap_json', $doc))->pluck('type')->unique()->all())->toBe(['same']);
});
