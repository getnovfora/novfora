<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| VisibleForumIds (P2-M3 ⚙): the query-level generalisation of ForumController's per-row forum.view check.
| null = sees all (no restriction sentinel); [] = sees none; otherwise the flat visible-forum-id set.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function vfiDenyForumView(int $groupId, string $scopeType, ?int $scopeId): void
{
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $groupId,
        'scope_type' => $scopeType, 'scope_id' => $scopeId, 'value' => -1, // NEVER (absolute)
    ]);
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
}

it('returns null (no restriction) when the viewer can see every forum', function () {
    Forum::create(['slug' => 'a', 'title' => 'A', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);

    expect(VisibleForumIds::for($member))->toBeNull();
});

it('excludes a forum the viewer lacks forum.view on', function () {
    $a = Forum::create(['slug' => 'a', 'title' => 'A', 'type' => 'forum']);
    $b = Forum::create(['slug' => 'b', 'title' => 'B', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);

    vfiDenyForumView((int) Group::where('slug', 'members')->value('id'), 'forum', (int) $b->id);

    $ids = VisibleForumIds::for($member);
    expect($ids)->toBeArray()
        ->and($ids)->toContain((int) $a->id)
        ->and($ids)->not->toContain((int) $b->id);
});

it('returns an empty array when the viewer can see no forum', function () {
    Forum::create(['slug' => 'a', 'title' => 'A', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);

    vfiDenyForumView((int) Group::where('slug', 'members')->value('id'), 'global', null);

    expect(VisibleForumIds::for($member))->toBe([]);
});

it('memoises per request and can be flushed', function () {
    Forum::create(['slug' => 'a', 'title' => 'A', 'type' => 'forum']);
    $member = Users::inGroups(['members', 'tl1']);

    expect(VisibleForumIds::for($member))->toBeNull();           // sees all → null, memoised
    vfiDenyForumView((int) Group::where('slug', 'members')->value('id'), 'global', null); // flushes memo too
    expect(VisibleForumIds::for($member))->toBe([]);             // re-resolved after the deny
});
