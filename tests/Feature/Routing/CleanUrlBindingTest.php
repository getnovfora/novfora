<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| BUG-002 / BUG-003 (locked 2026-06-19 — non-breaking dual resolver): Forum and User resolve route bindings
| by numeric id OR by slug/username, and generate the clean slug/username form. Both /forums/2 and
| /forums/announcements (and /users/6 and /users/tommy) must keep working. Additive and reversible — numeric
| resolution is preserved, no 301s. The resolver must not widen visibility (a trashed forum never resolves).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

describe('Forum slug binding (BUG-002)', function () {
    it('resolves by slug and by id, and generates the slug form', function () {
        $forum = Forum::create(['slug' => 'announcements', 'title' => 'Announcements', 'type' => 'forum']);

        expect($forum->getRouteKeyName())->toBe('slug')
            ->and(route('forums.show', $forum, false))->toBe('/forums/announcements');

        $bySlug = (new Forum)->resolveRouteBinding('announcements');
        $byId = (new Forum)->resolveRouteBinding((string) $forum->id);

        expect($bySlug?->is($forum))->toBeTrue()      // /forums/announcements (the bug: used to 404)
            ->and($byId?->is($forum))->toBeTrue()       // /forums/2 still resolves (dual resolver)
            ->and((new Forum)->resolveRouteBinding('does-not-exist'))->toBeNull();
    });

    it('does not resolve a soft-deleted forum — the resolver never widens visibility', function () {
        $forum = Forum::create(['slug' => 'archived', 'title' => 'Archived', 'type' => 'forum']);
        $forum->delete();

        expect((new Forum)->resolveRouteBinding('archived'))->toBeNull()
            ->and((new Forum)->resolveRouteBinding((string) $forum->id))->toBeNull();
    });

    it('serves both URL forms over HTTP', function () {
        $admin = Users::inGroups(['admins']);
        $forum = Forum::create(['slug' => 'announcements', 'title' => 'Announcements', 'type' => 'forum']);

        $this->actingAs($admin)->get('/forums/announcements')->assertOk();
        $this->actingAs($admin)->get('/forums/'.$forum->id)->assertOk();
    });
});

describe('User username binding (BUG-003)', function () {
    it('resolves by username and by id, and generates the username form', function () {
        $user = User::factory()->create(['username' => 'tommy']);

        expect($user->getRouteKeyName())->toBe('username')
            ->and(route('profiles.show', $user, false))->toBe('/users/tommy');

        expect((new User)->resolveRouteBinding('tommy')?->is($user))->toBeTrue()
            ->and((new User)->resolveRouteBinding((string) $user->id)?->is($user))->toBeTrue();
    });

    it('keeps a null-username user reachable by id, with id-fallback URL generation (no 500)', function () {
        $legacy = User::factory()->create(['username' => null]);

        // Generation falls back to the numeric id rather than producing a broken /users/ link…
        expect(route('profiles.show', $legacy, false))->toBe('/users/'.$legacy->id)
            // …and that id form still resolves the right user.
            ->and((new User)->resolveRouteBinding((string) $legacy->id)?->is($legacy))->toBeTrue();
    });

    it('serves both URL forms over HTTP (profiles are public read)', function () {
        $user = User::factory()->create(['username' => 'tommy']);

        $this->get('/users/tommy')->assertOk();
        $this->get('/users/'.$user->id)->assertOk();
    });
});
