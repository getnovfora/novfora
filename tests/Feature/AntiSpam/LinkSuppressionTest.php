<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The teeth of the trust gate (security §2.4 / ADR-0007): a gated author's links/images are SUPPRESSED in
| the rendered HTML, while their text survives. Enforced server-side from the canonical (which stays
| lossless) and resolved through the same permission engine that seeds the TL0 NEVER.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function forumForSuppression(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

/** A doc with a hyperlink mark and an inline image — the two TL0 spam vectors. @return array<string,mixed> */
function docWithLinkAndImage(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'visit '],
            ['type' => 'text', 'text' => 'this site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://spam.example']]]],
        ]],
        ['type' => 'image', 'attrs' => ['src' => 'https://spam.example/x.png', 'alt' => 'banner']],
    ]];
}

it('suppresses links and images for a TL0 author (text survives, URLs do not)', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = app(PostService::class)->createTopic($tl0, forumForSuppression(), 'New here', 'tiptap_json', docWithLinkAndImage());
    $html = $topic->posts()->firstOrFail()->body_html_cache;

    expect($html)->toContain('this site')      // the link's text is preserved
        ->not->toContain('<a ')                 // …but the anchor is gone
        ->not->toContain('spam.example')         // …and so is the URL (href + img src)
        ->not->toContain('<img');                // the inline image is dropped entirely
});

it('keeps links and images for a TL1 author', function () {
    $tl1 = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($tl1, forumForSuppression(), 'Trusted', 'tiptap_json', docWithLinkAndImage());
    $html = $topic->posts()->firstOrFail()->body_html_cache;

    expect($html)->toContain('<a ')
        ->toContain('href="https://spam.example"')
        ->toContain('<img');
});

it('suppresses links in Markdown mode too (uniform across input formats)', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = app(PostService::class)->createTopic(
        $tl0, forumForSuppression(), 'Md', 'markdown', ['source' => 'see [my site](https://spam.example) now'],
    );
    $html = $topic->posts()->firstOrFail()->body_html_cache;

    expect($html)->toContain('my site')->not->toContain('<a ')->not->toContain('spam.example');
});

it('preserves the link in the lossless canonical even when suppressed (ADR-0005)', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = app(PostService::class)->createTopic($tl0, forumForSuppression(), 'Keep source', 'tiptap_json', docWithLinkAndImage());
    $post = $topic->posts()->firstOrFail();

    // The display HTML is suppressed, but the canonical source is untouched — so a later promotion +
    // re-render can reveal it, and the author's edit reopens exactly what they wrote.
    expect(json_encode($post->body_canonical))->toContain('spam.example');
    expect($post->body_html_cache)->not->toContain('spam.example');
});
