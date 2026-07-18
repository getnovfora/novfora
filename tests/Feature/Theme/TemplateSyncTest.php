<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SiteTemplate;
use App\Theme\Sandbox\TemplateContract;
use App\Theme\Sandbox\TemplateService;
use App\Theme\Sandbox\TemplateSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| U11 (NOV-109 / ADR-0112) — TemplateSync: reconciling stored overrides against changed shipped defaults.
| The invariants: a conflict NEVER touches the stored source (old keeps rendering — "never fatal"), merged
| output must re-pass the sandbox gate, and every explicit save stamps the base it derived from.
*/

uses(RefreshDatabase::class);

it('stamps base_source on every explicit save', function () {
    $svc = app(TemplateService::class);
    $svc->save('home_welcome', TemplateContract::default('home_welcome')."\n<p>mine</p>");

    $row = SiteTemplate::where('template_key', 'home_welcome')->firstOrFail();
    expect($row->base_source)->toBe(TemplateContract::default('home_welcome'))
        ->and($row->merge_state)->toBe('current');
});

it('reports unchanged when the default did not move', function () {
    app(TemplateService::class)->save('home_welcome', TemplateContract::default('home_welcome')."\n<p>mine</p>");

    expect(app(TemplateSync::class)->sync())->toBe(['home_welcome' => 'unchanged']);
});

it('follows the new default wholesale when the admin never diverged', function () {
    $svc = app(TemplateService::class);
    $svc->save('home_welcome', TemplateContract::default('home_welcome')); // enabled, un-edited

    $newDefault = TemplateContract::default('home_welcome')."\n<p>new-in-release</p>";
    $report = app(TemplateSync::class)->sync(['home_welcome' => $newDefault]);

    $row = SiteTemplate::where('template_key', 'home_welcome')->firstOrFail();
    expect($report)->toBe(['home_welcome' => 'merged'])
        ->and($row->source)->toBe($newDefault)
        ->and($row->base_source)->toBe($newDefault)
        ->and($row->merge_state)->toBe('merged');
});

it('cleanly merges a disjoint admin edit with a disjoint default change', function () {
    $base = TemplateContract::default('home_welcome'); // 5 lines
    $lines = explode("\n", $base);

    // The admin edits ONE line; the release changes a DIFFERENT line.
    $ours = $lines;
    $ours[0] = '<div class="rounded-lg border border-line bg-surface-raised p-4" data-mine="1">';
    app(TemplateService::class)->save('home_welcome', implode("\n", $ours));

    $theirs = $lines;
    $theirs[count($lines) - 1] = '</div><p>release-addition</p>';

    $report = app(TemplateSync::class)->sync(['home_welcome' => implode("\n", $theirs)]);

    $row = SiteTemplate::where('template_key', 'home_welcome')->firstOrFail();
    expect($report)->toBe(['home_welcome' => 'merged'])
        ->and($row->source)->toContain('data-mine="1"')
        ->and($row->source)->toContain('release-addition')
        ->and($row->merge_state)->toBe('merged')
        ->and($row->base_source)->toBe(implode("\n", $theirs));
});

it('flags a conflict WITHOUT touching the stored source (old keeps rendering)', function () {
    // topic_footer's default is a single line — an admin edit + a default change must overlap.
    $mine = '{% if topic.reply_count > 0 %}<p class="mt-4 text-xs">my edited footer</p>{% endif %}';
    app(TemplateService::class)->save('topic_footer', $mine);

    $report = app(TemplateSync::class)->sync(['topic_footer' => '<p>totally new default</p>']);

    $row = SiteTemplate::where('template_key', 'topic_footer')->firstOrFail();
    expect($report)->toBe(['topic_footer' => 'conflict'])
        ->and($row->source)->toBe($mine)                     // untouched
        ->and($row->merge_state)->toBe('conflict')
        ->and($row->base_source)->toBe(TemplateContract::default('topic_footer')); // base NOT advanced

    // The old override still renders — a conflict is never fatal.
    $html = app(TemplateService::class)->render('topic_footer', ['topic' => ['title' => 'T', 'reply_count' => 3]]);
    expect($html)->toContain('my edited footer');
});

it('demotes a lint-failing clean merge to a conflict (the split-token gate)', function () {
    // A raw row NOT authored through save() (defence-in-depth): its lines interleave with the new default
    // into an UNBALANCED {% if %} — parse fails on the merged output, so the merge must be refused.
    SiteTemplate::create([
        'template_key' => 'home_welcome',
        'source' => "A\n{% if user.is_guest %}\nB\nC",
        'base_source' => "A\nB\nC",
        'is_enabled' => true,
        'merge_state' => 'current',
    ]);

    $report = app(TemplateSync::class)->sync(['home_welcome' => "A\nB\nC2"]);

    $row = SiteTemplate::where('template_key', 'home_welcome')->firstOrFail();
    expect($report)->toBe(['home_welcome' => 'conflict'])
        ->and($row->source)->toBe("A\n{% if user.is_guest %}\nB\nC")
        ->and($row->merge_state)->toBe('conflict');
});

it('stamps a legacy row missing base_source and leaves orphaned keys alone', function () {
    SiteTemplate::create(['template_key' => 'home_welcome', 'source' => '<p>x</p>', 'is_enabled' => true]);
    SiteTemplate::query()->where('template_key', 'home_welcome')->update(['base_source' => null]);
    SiteTemplate::create(['template_key' => 'gone_key', 'source' => '<p>y</p>', 'base_source' => '<p>y</p>', 'is_enabled' => true]);

    $report = app(TemplateSync::class)->sync();

    expect($report['home_welcome'])->toBe('stamped')
        ->and($report['gone_key'])->toBe('orphan')
        ->and(SiteTemplate::where('template_key', 'home_welcome')->value('base_source'))->toBe(TemplateContract::default('home_welcome'))
        ->and(SiteTemplate::where('template_key', 'gone_key')->value('source'))->toBe('<p>y</p>');
});

it('resolves keep-mine (base advances, source stays) and take-default (source resets)', function () {
    $mine = '<p>my footer</p>';
    app(TemplateService::class)->save('topic_footer', $mine);
    SiteTemplate::query()->where('template_key', 'topic_footer')->update(['merge_state' => 'conflict']);

    app(TemplateSync::class)->resolveKeepMine('topic_footer');
    $row = SiteTemplate::where('template_key', 'topic_footer')->firstOrFail();
    expect($row->source)->toBe($mine)
        ->and($row->merge_state)->toBe('current')
        ->and($row->base_source)->toBe(TemplateContract::default('topic_footer'));

    app(TemplateSync::class)->resolveTakeDefault('topic_footer');
    $row->refresh();
    expect($row->source)->toBe(TemplateContract::default('topic_footer'))
        ->and($row->merge_state)->toBe('current');
});

it('exposes pending review state for the ACP banner', function () {
    expect(app(TemplateSync::class)->hasPendingReview())->toBeFalse();

    app(TemplateService::class)->save('topic_footer', '<p>z</p>');
    SiteTemplate::query()->where('template_key', 'topic_footer')->update(['merge_state' => 'conflict']);

    expect(app(TemplateSync::class)->hasPendingReview())->toBeTrue();
});
