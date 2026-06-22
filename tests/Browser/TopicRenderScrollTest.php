<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| M0 regression guard (design-polish Slice 0), in-browser. The audit's worst-rated defect was the
| `.novfora-prose` height cap (max-height:28rem; overflow-y:auto) leaking from the EDITOR box onto rendered
| posts, trapping scroll inside long posts. The M0 fix scoped the cap to `.novfora-editor .novfora-prose`
| (resources/css/app.css), so a rendered post grows with its content and the PAGE scrolls. This spec proves
| a long rendered post does not carry an inner scroll cap. Requires Chrome — `php artisan dusk`. CI-pending
| on the baseline gate box (no headless browser).
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->author = Users::inGroups(['members', 'tl4'], ['username' => 'ada', 'email' => 'ada@novfora.test']);
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
});

it('does not trap scroll inside a long rendered post (the .novfora-prose cap is editor-only)', function () {
    // A post far taller than the old 28rem editor cap.
    $paras = collect(range(1, 60))->map(fn ($i) => [
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => "Long paragraph number {$i} — this post must grow the page, not trap scroll."]],
    ])->all();

    $topic = app(PostService::class)->createTopic(
        $this->author, $this->forum, 'A very long post', 'tiptap_json', ['type' => 'doc', 'content' => $paras]
    );

    $this->browse(function (Browser $browser) use ($topic) {
        $browser->visit(route('topics.show', $topic))
            ->waitFor('.novfora-prose', 15)
            ->assertSee('Long paragraph number 1')
            ->assertSee('Long paragraph number 60'); // the tail is on-page, not hidden behind an inner scrollbar

        // The rendered post body must NOT carry an inner-scroll cap (that lived on the editor box pre-M0).
        $overflowY = $browser->script(
            "return getComputedStyle(document.querySelector('.novfora-prose')).overflowY;"
        )[0];

        expect($overflowY)->not->toBe('auto')->and($overflowY)->not->toBe('scroll');
    });
});
