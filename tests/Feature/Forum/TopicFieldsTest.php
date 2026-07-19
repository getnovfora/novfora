<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\TopicFieldException;
use App\Models\Forum;
use App\Models\TopicField;
use App\Models\TopicFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| U19 (NOV-116) — custom TOPIC fields: forum-scoped, typed, server-side-validated (values come from topic
| creators), captured at createTopic and rendered on the topic header. ACP CRUD gated on admin.settings.
*/

uses(RefreshDatabase::class);

function u19Forum(string $slug = 'bugs'): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

function u19Author(string $suffix = 'a'): User
{
    return Users::inGroups(['members', 'tl2'], ['username' => 'u19-op-'.$suffix, 'email' => 'u19-op-'.$suffix.'@t.test']);
}

it('captures valid topic-field values at creation and renders them on the topic', function () {
    $this->seed();
    $forum = u19Forum();
    $field = TopicField::create(['key' => 'version', 'label' => 'Version', 'type' => 'text', 'is_required' => true, 'is_active' => true]);

    $topic = app(PostService::class)->createTopic(u19Author(), $forum, 'Crash on save', 'markdown', ['source' => 'op'], null, ['version' => '1.4.0']);

    expect(TopicFieldValue::where('topic_id', $topic->id)->where('topic_field_id', $field->id)->value('value'))->toBe('1.4.0');

    $this->get(route('topics.show', $topic))->assertOk()->assertSee('Version')->assertSee('1.4.0');
});

it('rejects a missing required value, a non-http URL, and a select value outside its options', function () {
    $this->seed();
    $forum = u19Forum();
    TopicField::create(['key' => 'version', 'label' => 'Version', 'type' => 'text', 'is_required' => true, 'is_active' => true]);
    TopicField::create(['key' => 'link', 'label' => 'Link', 'type' => 'url', 'is_active' => true]);
    TopicField::create(['key' => 'sev', 'label' => 'Severity', 'type' => 'select', 'options' => ['low', 'high'], 'is_active' => true]);
    $author = u19Author();

    // Missing required → throws.
    expect(fn () => app(PostService::class)->createTopic($author, $forum, 'A', 'markdown', ['source' => 'op'], null, []))
        ->toThrow(TopicFieldException::class);
    // A javascript: URL is not a valid http(s) URL → throws (can never be rendered as a link).
    expect(fn () => app(PostService::class)->createTopic($author, $forum, 'B', 'markdown', ['source' => 'op'], null, ['version' => '1', 'link' => 'javascript:alert(1)']))
        ->toThrow(TopicFieldException::class);
    // A select value not in the field's options → throws.
    expect(fn () => app(PostService::class)->createTopic($author, $forum, 'C', 'markdown', ['source' => 'op'], null, ['version' => '1', 'sev' => 'critical']))
        ->toThrow(TopicFieldException::class);

    // A fully-valid set succeeds.
    $topic = app(PostService::class)->createTopic($author, $forum, 'D', 'markdown', ['source' => 'op'], null, ['version' => '1', 'link' => 'https://example.test/x', 'sev' => 'high']);
    expect(TopicFieldValue::where('topic_id', $topic->id)->count())->toBe(3);
});

it('rejects a URL with embedded credentials or over the length cap (U19 focused-review LOWs)', function () {
    $this->seed();
    $forum = u19Forum();
    TopicField::create(['key' => 'link', 'label' => 'Link', 'type' => 'url', 'is_active' => true]);
    $author = u19Author();

    // Embedded userinfo — a phishing vector (trusted-looking host is actually userinfo).
    expect(fn () => app(PostService::class)->createTopic($author, $forum, 'A', 'markdown', ['source' => 'op'], null, ['link' => 'https://trusted.example@evil.test']))
        ->toThrow(TopicFieldException::class);
    // Over the 255-char cap (client maxlength is only a hint).
    $longUrl = 'https://example.test/'.str_repeat('a', 300);
    expect(fn () => app(PostService::class)->createTopic($author, $forum, 'B', 'markdown', ['source' => 'op'], null, ['link' => $longUrl]))
        ->toThrow(TopicFieldException::class);
});

it('scopes a field to its forum — a forum-specific field does not apply elsewhere', function () {
    $this->seed();
    $bugs = u19Forum('bugs');
    $chat = u19Forum('chat');
    TopicField::create(['key' => 'repro', 'label' => 'Repro steps', 'type' => 'text', 'is_required' => true, 'is_active' => true, 'forum_id' => $bugs->id]);

    // Required in `bugs`…
    expect(fn () => app(PostService::class)->createTopic(u19Author('bugs'), $bugs, 'X', 'markdown', ['source' => 'op'], null, []))
        ->toThrow(TopicFieldException::class);
    // …but absent (and not required) in `chat`.
    $topic = app(PostService::class)->createTopic(u19Author('chat'), $chat, 'Y', 'markdown', ['source' => 'op'], null, []);
    expect($topic->exists)->toBeTrue();
});

it('lets a 2FA admin manage topic fields and gates a plain member out', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    $this->post(route('admin.topic-fields.store'), ['key' => 'version', 'label' => 'Version', 'type' => 'text'])->assertRedirect();
    expect(TopicField::where('key', 'version')->exists())->toBeTrue();

    // A select with no choices is refused.
    $this->post(route('admin.topic-fields.store'), ['key' => 'sev', 'label' => 'Severity', 'type' => 'select', 'options' => ''])
        ->assertSessionHasErrors('options');

    $this->actingAs(Users::inGroups(['members']));
    $this->get(route('admin.topic-fields'))->assertForbidden();
});
