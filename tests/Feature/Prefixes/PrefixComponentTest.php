<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Prefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function componentForum(): Forum
{
    return Forum::firstOrCreate(['slug' => 'comp-board'], ['title' => 'Comp Board', 'type' => 'forum']);
}

// ── ACP: admin.prefixes component ─────────────────────────────────────────────────────────────────────

it('blocks a non-admin from the prefix component (403)', function () {
    $member = Users::inGroups(['members']);
    $this->actingAs($member);

    Livewire::test('admin.prefixes')->assertStatus(403);
});

it('blocks a guest from the prefix component (403)', function () {
    Livewire::test('admin.prefixes')->assertStatus(403);
});

it('allows a 2FA admin to see and create prefixes via the component', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.prefixes')
        ->call('newPrefix')
        ->set('label', 'Tutorial')
        ->set('colorToken', 'blue')
        ->call('save')
        ->assertHasNoErrors();

    expect(Prefix::where('label', 'Tutorial')->exists())->toBeTrue();
});

it('admin can edit a prefix via the component', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $prefix = Prefix::create(['label' => 'Old', 'color_token' => null, 'position' => 0]);

    Livewire::test('admin.prefixes')
        ->call('edit', $prefix->id)
        ->set('label', 'New')
        ->call('save')
        ->assertHasNoErrors();

    expect($prefix->fresh()->label)->toBe('New');
});

it('admin can delete a prefix via the component', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $prefix = Prefix::create(['label' => 'ToDelete', 'color_token' => null, 'position' => 0]);

    Livewire::test('admin.prefixes')
        ->call('askDelete', $prefix->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Prefix::find($prefix->id))->toBeNull();
});

// ── create-topic: prefix selector ─────────────────────────────────────────────────────────────────────

it('create-topic attaches a valid prefix to the topic', function () {
    $forum = componentForum();
    $author = Users::withTwoFactor(Users::inGroups(['members', 'tl2']));
    $this->actingAs($author);

    $prefix = Prefix::create(['label' => 'Guide', 'color_token' => 'blue', 'forum_id' => null, 'position' => 0]);

    $topic = app(PostService::class)->createTopic(
        $author,
        $forum,
        'Topic with prefix',
        'markdown',
        ['source' => 'Body.'],
        $prefix->id,
    );

    expect((int) $topic->prefix_id)->toBe((int) $prefix->id);
});

it('create-topic rejects a prefix that belongs to another forum', function () {
    $forum = componentForum();
    $otherForum = Forum::firstOrCreate(['slug' => 'other'], ['title' => 'Other', 'type' => 'forum']);
    $author = Users::withTwoFactor(Users::inGroups(['members', 'tl2']));
    $this->actingAs($author);

    // Prefix locked to $otherForum — not valid for $forum.
    $prefix = Prefix::create(['label' => 'Wrong', 'color_token' => null, 'forum_id' => $otherForum->id, 'position' => 0]);

    $topic = app(PostService::class)->createTopic(
        $author,
        $forum,
        'Topic with wrong prefix',
        'markdown',
        ['source' => 'Body.'],
        $prefix->id,
    );

    // prefix_id must be null — the wrong-forum prefix was rejected.
    expect($topic->prefix_id)->toBeNull();
});
