<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\IgnoreService;
use App\Community\WallService;
use App\Models\ProfilePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| U-91 / ◆-lite profile wall. The load-bearing assertions are the two read fences — a PENDING status is
| visible ONLY to its own author (never the owner or anyone else while it sits in the queue), and an author
| the VIEWER ignores is dropped — plus the posting gate (a guest, and an author the OWNER ignores, are
| refused). Content moves through the shared render → moderate → word-filter pipeline; only the sanitised
| display cache is ever stored.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function wsvc(): WallService
{
    return app(WallService::class);
}

it('posts an approved status a trusted author can leave, visible to everyone', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);

    $status = wsvc()->post($author, $owner, 'tiptap_json', Content::doc('Nice profile!'));

    expect($status->approved_state)->toBe('approved')
        ->and((int) $status->profile_user_id)->toBe((int) $owner->id)
        ->and(wsvc()->visibleWall($owner, $owner)->pluck('id'))->toContain($status->id)                       // owner sees it
        ->and(wsvc()->visibleWall(Users::inGroups(['members', 'tl1']), $owner)->pluck('id'))->toContain($status->id); // a stranger too
});

it('holds a new (TL0) author’s status as pending and shows it back only to that author', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $tl0 = Users::inGroups(['members', 'tl0']);
    $other = Users::inGroups(['members', 'tl1']);

    $status = wsvc()->post($tl0, $owner, 'tiptap_json', Content::doc('hello wall'));
    expect($status->approved_state)->toBe('pending');

    // Visible to its own author; hidden from the wall OWNER, any other viewer, and a guest while pending.
    expect(wsvc()->visibleWall($tl0, $owner)->pluck('id'))->toContain($status->id)
        ->and(wsvc()->visibleWall($owner, $owner)->pluck('id'))->not->toContain($status->id)
        ->and(wsvc()->visibleWall($other, $owner)->pluck('id'))->not->toContain($status->id)
        ->and(wsvc()->visibleWall(null, $owner)->pluck('id'))->not->toContain($status->id);
});

it('drops wall statuses from an author the viewer ignores', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);
    $status = wsvc()->post($author, $owner, 'tiptap_json', Content::doc('a status'));

    $hater = Users::inGroups(['members', 'tl1']);
    app(IgnoreService::class)->ignore($hater, $author);

    expect(wsvc()->visibleWall($hater, $owner)->pluck('id'))->not->toContain($status->id)                      // ignored → hidden
        ->and(wsvc()->visibleWall(Users::inGroups(['members', 'tl1']), $owner)->pluck('id'))->toContain($status->id); // others still see it
});

it('blocks posting when the owner ignores the author, and never lets a guest post', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $blocked = Users::inGroups(['members', 'tl2']);
    app(IgnoreService::class)->ignore($owner, $blocked); // the OWNER ignores the would-be poster

    expect(wsvc()->canPostOn($blocked, $owner))->toBeFalse()
        ->and(wsvc()->canPostOn(null, $owner))->toBeFalse()                                    // a guest
        ->and(wsvc()->canPostOn(Users::inGroups(['members', 'tl1']), $owner))->toBeTrue();     // a normal member may

    // The SFC re-gates: the blocked poster's save is a 403, and no row is written.
    Livewire::actingAs($blocked)->test('community.wall-composer', ['profileUserId' => $owner->id])
        ->set('canonicalJson', Content::doc('let me in'))
        ->call('save')
        ->assertStatus(403);
    expect(ProfilePost::where('user_id', $blocked->id)->count())->toBe(0);
});

it('sanitises status content — a script in the canonical never reaches the display cache', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);

    $status = wsvc()->post($author, $owner, 'tiptap_json', Content::doc('<script>alert(document.cookie)</script>'));

    expect($status->body_html_cache)->not->toContain('<script'); // escaped as text, never an executable tag
});

it('gates deletion: author, owner, and moderator may; a stranger may not (the contract pair)', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);
    $status = wsvc()->post($author, $owner, 'tiptap_json', Content::doc('deletable'));
    $stranger = Users::inGroups(['members', 'tl1']);
    $mod = Users::inGroups(['moderators']);

    expect(wsvc()->canDelete($author, $status))->toBeTrue()
        ->and(wsvc()->canDelete($owner, $status))->toBeTrue()
        ->and(wsvc()->canDelete($mod, $status))->toBeTrue()
        ->and(wsvc()->canDelete($stranger, $status))->toBeFalse();

    $this->actingAs($stranger)->delete(route('wall.destroy', $status))->assertForbidden();
    expect($status->fresh()->trashed())->toBeFalse();

    $this->actingAs($author)->delete(route('wall.destroy', $status))->assertRedirect();
    expect($status->fresh()->trashed())->toBeTrue();
});

it('surfaces a held wall post in the moderation queue for a global mod to approve', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $tl0 = Users::inGroups(['members', 'tl0']);
    $status = wsvc()->post($tl0, $owner, 'tiptap_json', Content::doc('held status'));
    expect($status->approved_state)->toBe('pending');

    // A global moderator sees the approve control; a plain member is refused at the endpoint (the pair).
    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod)->get(route('moderation.queue'))
        ->assertOk()->assertSee('action="'.route('wall-posts.approve', $status).'"', false);
    $this->actingAs(Users::inGroups(['members', 'tl1']))->post(route('wall-posts.approve', $status))->assertForbidden();

    // Approving it lifts the hold — now publicly visible; a held status is never orphaned.
    $this->actingAs($mod)->post(route('wall-posts.approve', $status))->assertRedirect();
    expect($status->fresh()->approved_state)->toBe('approved')
        ->and(wsvc()->visibleWall($owner, $owner)->pluck('id'))->toContain($status->id);
});

it('posts through the ⚡wall-composer end-to-end', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);

    Livewire::actingAs($author)->test('community.wall-composer', ['profileUserId' => $owner->id])
        ->set('canonicalJson', Content::doc('posted via the composer'))
        ->call('save')
        ->assertHasNoErrors();

    expect(ProfilePost::where('profile_user_id', $owner->id)->where('user_id', $author->id)->count())->toBe(1);
});

it('renders the profile Wall tab — composer for a permitted viewer, plus an approved status', function () {
    $owner = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl2']);
    wsvc()->post($author, $owner, 'tiptap_json', Content::doc('a rendered status body'));

    $this->actingAs($author)->get(route('profiles.show', ['user' => $owner, 'tab' => 'wall']))
        ->assertOk()
        ->assertSee('a rendered status body')       // the sanitised status renders in the list
        ->assertSee('dusk="wall-post"', false);      // the composer is present (viewer may post)
});
