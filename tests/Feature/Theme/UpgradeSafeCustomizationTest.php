<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\SiteTemplate;
use App\Theme\Sandbox\TemplateContract;
use App\Theme\Sandbox\TemplateService;
use App\Theme\Sandbox\TemplateSync;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| GATE 4B EXIT PROOF (U11 / ADR-0112): a THEMED + TEMPLATE-MODDED install crosses a simulated core release
| with its mods intact — the clean-mergeable override auto-merges keeping the admin's edit, hook fragments
| are untouched by construction, a genuinely conflicting override KEEPS RENDERING its old source with the
| conflict surfaced (never fatal), and the style theme is unaffected. No path throws.
*/

uses(RefreshDatabase::class);

it('survives a simulated core release with mods intact and conflicts non-fatal (Gate 4B)', function () {
    $this->seed();

    // ── The customized install ──────────────────────────────────────────────────────────────────
    // 1. A style theme, active.
    $styleManager = app(StyleThemeManager::class);
    $styleManager->create(['name' => 'House style', 'tokens' => ['surface' => '#fffdf5'], 'activate' => true]);

    // 2. A modified home_welcome override (admin edits the FIRST line of the multi-line default).
    $svc = app(TemplateService::class);
    $welcomeLines = explode("\n", TemplateContract::default('home_welcome'));
    $welcomeLines[0] = '<div class="rounded-lg border border-line bg-surface-raised p-4" data-admin-mod="1">';
    $svc->save('home_welcome', implode("\n", $welcomeLines));

    // 3. A modified topic_footer override (single-line default — an overlap is unavoidable later).
    $svc->save('topic_footer', '<p class="mt-4 text-center text-xs">custom footer survives</p>');

    // 4. Two hook fragments.
    $svc->saveHook(null, 'site.header.after', 'Banner', '<p>hook-banner-alive</p>');
    $svc->saveHook(null, 'topic.after_posts', 'Outro', '<p>hook-outro-alive</p>');

    // ── The simulated core release: home_welcome's default changes DISJOINTLY (last line), topic_footer's
    //    default is rewritten entirely (overlaps the admin's edit → a real conflict). ──────────────
    $newWelcome = $welcomeLines;
    $newWelcome[0] = explode("\n", TemplateContract::default('home_welcome'))[0]; // back to stock line 0 (theirs didn't change it)
    $newWelcome[count($newWelcome) - 1] = '  <p class="mt-2 text-xs">release-improved-stats</p></div>';
    $newDefaults = [
        'home_welcome' => implode("\n", $newWelcome),
        'topic_footer' => '<p>an entirely rewritten default</p>',
    ];

    $report = app(TemplateSync::class)->sync($newDefaults);

    expect($report['home_welcome'])->toBe('merged')
        ->and($report['topic_footer'])->toBe('conflict');

    // ── Proof on the live pages ─────────────────────────────────────────────────────────────────
    $author = Users::inGroups(['members', 'tl2']);
    $forum = Forum::create(['slug' => 'gate4b', 'title' => 'Gate 4B board', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Gate 4B topic', 'markdown', ['source' => 'Opening post.']);

    // The merged welcome renders BOTH the admin's edit and the release's improvement.
    $home = $this->get(route('forums.index'));
    $home->assertOk()
        ->assertSee('data-admin-mod="1"', false)
        ->assertSee('release-improved-stats')
        ->assertSee('hook-banner-alive');

    // The conflicted footer keeps rendering the ADMIN's old source — stale but working, never fatal.
    $this->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('custom footer survives')
        ->assertDontSee('an entirely rewritten default')
        ->assertSee('hook-outro-alive');

    // The style theme is untouched by the sync.
    expect($styleManager->css())->toContain('--surface:#fffdf5;');

    // The conflict is surfaced for review, and resolving keep-mine clears it with the source intact.
    expect(app(TemplateSync::class)->hasPendingReview())->toBeTrue();
    app(TemplateSync::class)->resolveKeepMine('topic_footer');
    $row = SiteTemplate::where('template_key', 'topic_footer')->firstOrFail();
    expect($row->merge_state)->toBe('current')
        ->and($row->source)->toContain('custom footer survives');
});

it('runs the same reconcile lazily when the ACP templates page is opened', function () {
    $this->seed();
    $svc = app(TemplateService::class);
    $svc->save('topic_footer', '<p>my footer</p>');

    // Simulate a deploy that changed the default WITHOUT the upgrade-path sync having run: rewrite the
    // stored base so it differs from the live contract default.
    SiteTemplate::query()->where('template_key', 'topic_footer')->update(['base_source' => '<p>an older default</p>']);

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    Livewire\Livewire::test('admin.settings.templates');

    // ours ≠ base, theirs (live default) ≠ base, single line → conflict, surfaced, source intact.
    $row = SiteTemplate::where('template_key', 'topic_footer')->firstOrFail();
    expect($row->merge_state)->toBe('conflict')
        ->and($row->source)->toBe('<p>my footer</p>');
});
