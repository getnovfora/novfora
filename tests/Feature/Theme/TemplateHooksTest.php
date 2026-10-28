<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\SiteTemplateHook;
use App\Theme\Sandbox\SandboxException;
use App\Theme\Sandbox\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U11 (NOV-109 / ADR-0112) — template-hook fragments: admin sandbox snippets on named anchors, rendered
| through the SAME sandbox, per-fragment isolated, cached to zero steady-state queries, upgrade-safe by
| construction (anchored by name).
*/

uses(RefreshDatabase::class);

it('renders enabled fragments at their anchor in position order, skipping disabled ones', function () {
    $svc = app(TemplateService::class);
    $svc->saveHook(null, 'site.header.after', 'Second', '<p>second</p>', 20);
    $svc->saveHook(null, 'site.header.after', 'First', '<p>first</p>', 10);
    $off = $svc->saveHook(null, 'site.header.after', 'Off', '<p>hidden</p>', 30);
    $svc->setHookEnabled($off->id, false);

    $html = $svc->renderHooks('site.header.after');
    expect($html)->toBe('<p>first</p><p>second</p>')
        ->and($svc->renderHooks('unknown.anchor'))->toBe('');
});

it('escapes dynamic values and refuses script literals at save (the sandbox gate)', function () {
    $svc = app(TemplateService::class);
    $svc->saveHook(null, 'topic.header', 'Title echo', '<p>{{ topic.title }}</p>');

    $html = $svc->renderHooks('topic.header', ['topic' => ['title' => '<script>alert(1)</script>']]);
    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>');

    expect(fn () => $svc->saveHook(null, 'topic.header', 'Evil', '<script>alert(1)</script>'))
        ->toThrow(SandboxException::class);
    expect(fn () => $svc->saveHook(null, 'not.an.anchor', 'Nope', '<p>x</p>'))
        ->toThrow(InvalidArgumentException::class);
});

it('isolates a broken fragment — its siblings still render', function () {
    $svc = app(TemplateService::class);
    $svc->saveHook(null, 'board.header', 'Good', '<p>good</p>', 1);
    // A raw row not authored through saveHook (defence-in-depth): unparseable source.
    SiteTemplateHook::create(['hook_key' => 'board.header', 'name' => 'Broken', 'source' => '{% if %}', 'position' => 2, 'is_enabled' => true]);
    $svc->invalidateHooks();

    expect($svc->renderHooks('board.header'))->toBe('<p>good</p>');
});

it('busts the fragment cache on every write', function () {
    $svc = app(TemplateService::class);
    $row = $svc->saveHook(null, 'site.footer.before', 'Note', '<p>v1</p>');
    expect($svc->renderHooks('site.footer.before'))->toBe('<p>v1</p>');

    $svc->saveHook($row->id, 'site.footer.before', 'Note', '<p>v2</p>');
    expect(app(TemplateService::class)->renderHooks('site.footer.before'))->toBe('<p>v2</p>');

    $svc->removeHook($row->id);
    expect(app(TemplateService::class)->renderHooks('site.footer.before'))->toBe('');
});

it('renders fragments on the real pages: site header, board, topic (incl. per-post), profile', function () {
    $this->seed();
    $svc = app(TemplateService::class);
    $svc->saveHook(null, 'site.header.after', 'Site banner', '<p>hook-site-banner</p>');
    $svc->saveHook(null, 'board.header', 'Board note', '<p>hook-board-{{ board.name }}</p>');
    $svc->saveHook(null, 'topic.post.footer', 'Post sig', '<p>hook-post-{{ post.author }}-{{ post.position }}</p>');
    $svc->saveHook(null, 'profile.header', 'Profile note', '<p>hook-profile-{{ profile.username }}</p>');

    $author = Users::inGroups(['members', 'tl2']);
    $forum = Forum::create(['slug' => 'hooked', 'title' => 'Hooked board', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A hooked topic', 'markdown', ['source' => 'Opening post.']);

    $this->get(route('forums.index'))->assertSee('hook-site-banner');
    $this->get(route('forums.show', $forum))->assertSee('hook-board-'.$forum->title);
    $this->get(route('topics.show', $topic))->assertSee('hook-post-'.$author->username.'-1');
    $this->get(route('profiles.show', $author))->assertSee('hook-profile-'.$author->username);
});

it('rejects the lint-skeleton bypass: a literal <script> hidden behind a string-literal tag — apex H1', function () {
    $svc = app(TemplateService::class);
    // A `{{` inside a {% %} string literal fooled the old regex skeleton into over-deleting the real
    // literal <script>, which then rendered raw. The AST-based scan catches it.
    // The lint (the save-time gate the {!! !!} hook render relies on) must REJECT it before storage —
    // the renderer emits literal text nodes raw by design, so lint is the escaping boundary here.
    $poc = '{% if "{{" %}<script>alert(document.cookie)</script>{{ \'\' }}{% endif %}';
    expect(fn () => $svc->saveHook(null, 'site.header.after', 'PoC', $poc))
        ->toThrow(SandboxException::class);
    // And the same source is rejected on the template-override + merge paths (all route through lint()).
    expect(fn () => $svc->save('home_welcome', $poc))->toThrow(SandboxException::class);
});

it('rejects slash-separated and svg event handlers and the data:text/html scheme — apex H2', function () {
    $svc = app(TemplateService::class);
    foreach ([
        '<img/onerror=alert(document.cookie) src=x>',
        '<svg/onload=alert(1)>',
        '<a href="data:text/html,<script>alert(1)</script>">x</a>',
        "<img\tonmouseover=alert(1) src=x>", // real tab (double-quoted) — whitespace separator
    ] as $evil) {
        expect(fn () => $svc->saveHook(null, 'topic.header', 'evil', $evil))
            ->toThrow(SandboxException::class);
    }

    // A benign <img> with a real src is still fine (no false positive).
    $ok = $svc->saveHook(null, 'topic.header', 'ok', '<img src="/logo.png" alt="logo">');
    expect($ok->source)->toContain('<img');
});

it('catches an event handler whose name is split across an interpolation (filler scan)', function () {
    $svc = app(TemplateService::class);
    expect(fn () => $svc->saveHook(null, 'site.header.after', 'split', '<img src=x on{{ "error" }}=alert(1)>'))
        ->toThrow(SandboxException::class);
});

it('gates hook management behind the admin SFC guard (non-admin 403)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));

    Livewire::test('admin.settings.templates')->assertStatus(403);
});

it('lets a 2FA admin manage fragments through the ACP editor', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.templates')
        ->call('newHook')
        ->set('hookKey', 'site.header.after')
        ->set('hookName', 'Banner')
        ->set('hookSource', '<p>from-acp</p>')
        ->call('saveHook')
        ->assertSet('showHookForm', false);

    $hook = SiteTemplateHook::where('name', 'Banner')->firstOrFail();
    expect($hook->hook_key)->toBe('site.header.after')
        ->and($hook->is_enabled)->toBeTrue();

    Livewire::test('admin.settings.templates')
        ->call('editHook', $hook->id)
        ->set('hookSource', '<script>x</script>')
        ->call('saveHook');
    expect($hook->refresh()->source)->toBe('<p>from-acp</p>'); // rejected save left it untouched

    Livewire::test('admin.settings.templates')->call('deleteHook', $hook->id);
    expect(SiteTemplateHook::find($hook->id))->toBeNull();
});
