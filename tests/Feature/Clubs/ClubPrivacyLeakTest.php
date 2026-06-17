<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// THE #1 FENCE (Phase 4 · M1.5): a private-hidden club and its content must NEVER leak through ANY surface.
// One explicit no-leak test per surface, each contrasting a non-member/guest (must NOT see) with a member
// (must see), built on the single-source-of-truth gates (ADR-0047): the guests-NEVER for anonymous surfaces
// + VisibleForumIds/clubContentVisibleTo for logged-in non-members.

use App\Api\ApiTokenService;
use App\Clubs\ClubMembershipService;
use App\Clubs\ClubService;
use App\Events\Reacted;
use App\Forum\PostService;
use App\Models\Bookmark;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use App\Webhooks\WebhookEventSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function leakFlush(): void
{
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    Cache::flush();
}

/**
 * A private (members-only, invite-only) club with one member-authored topic carrying a unique searchable
 * title token (ZZSECRET) and body token (QQSECRET).
 *
 * @return array{owner: User, member: User, outsider: User, club: Club, forum: Forum, topic: Topic}
 */
function leakFixture(string $privacy = 'private'): array
{
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'leak-owner-'.uniqid().'@leak.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Leak Club '.uniqid(), 'privacy' => $privacy]);
    $forum = $club->forum;

    $member = Users::inGroups(['members', 'tl1'], ['email' => 'leak-member-'.uniqid().'@leak.test']);
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'leak-out-'.uniqid().'@leak.test']);

    $topic = app(PostService::class)->createTopic($owner, $forum, 'ZZSECRET club topic', 'markdown', ['source' => 'QQSECRET hidden body']);

    leakFlush();

    return compact('owner', 'member', 'outsider', 'club', 'forum', 'topic');
}

// ── The two single-source-of-truth primitives ───────────────────────────────────────────────────────────

it('hard-denies guests forum.view on a private club forum (anonymous defence-in-depth)', function () {
    $f = leakFixture('private');
    expect(User::guest()->canDo('forum.view', $f['forum']->permissionScope()))->toBeFalse();

    // A public club carries no guests-NEVER — its content is public.
    $pub = leakFixture('public');
    expect(User::guest()->canDo('forum.view', $pub['forum']->permissionScope()))->toBeTrue();
});

it('excludes a private club forum from VisibleForumIds for a non-member but not a member', function () {
    $f = leakFixture('private');

    $outIds = VisibleForumIds::for($f['outsider']->fresh());
    expect($outIds)->not->toBeNull();
    expect($outIds)->not->toContain((int) $f['forum']->id);

    expect($f['forum']->clubContentVisibleTo($f['member']->fresh()))->toBeTrue();
    expect($f['forum']->clubContentVisibleTo($f['outsider']->fresh()))->toBeFalse();
});

// ── Surface: sitemap ─────────────────────────────────────────────────────────────────────────────────────

it('keeps private club content out of the sitemap', function () {
    $f = leakFixture('private');
    leakFlush();

    $this->get('/sitemap.xml')->assertOk()->assertDontSee('/topics/'.$f['topic']->id);
});

// ── Surface: RSS/Atom ───────────────────────────────────────────────────────────────────────────────────

it('404s a private club forum RSS feed for everyone (guest resolution)', function () {
    $f = leakFixture('private');

    $this->get(route('feeds.forum', $f['forum']))->assertNotFound();
});

// ── Surface: search (faceted + typeahead) ───────────────────────────────────────────────────────────────

it('hides private club content from search for a non-member but shows it to a member', function () {
    $f = leakFixture('private');

    $this->actingAs($f['outsider']->fresh())->get(route('search.index', ['q' => 'QQSECRET']))
        ->assertOk()->assertDontSee('ZZSECRET');

    $this->actingAs($f['member']->fresh())->get(route('search.index', ['q' => 'QQSECRET']))
        ->assertOk()->assertSee('ZZSECRET');
});

it('keeps a private club name out of the search forum-facet dropdown for a non-member (P5.1)', function () {
    $f = leakFixture('private');
    $name = $f['club']->name; // the club's discussion forum title == the club name

    // The facet dropdown (no query needed — it renders on the bare /search page) must not disclose the name.
    $this->actingAs($f['outsider']->fresh())->get(route('search.index'))
        ->assertOk()->assertDontSee($name);

    // A member sees their club's forum in the facet, confirming the gate is name-aware, not blanket-hiding.
    $this->actingAs($f['member']->fresh())->get(route('search.index'))
        ->assertOk()->assertSee($name);
});

// ── Surface: what's-new ──────────────────────────────────────────────────────────────────────────────────

it('hides private club topics from what is-new for a non-member', function () {
    $f = leakFixture('private');
    // What's-new only lists topics newer than the viewer's account; age both viewers so the topic qualifies.
    $f['member']->forceFill(['created_at' => now()->subDay()])->save();
    $f['outsider']->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($f['outsider']->fresh())->get(route('whats-new'))->assertOk()->assertDontSee('ZZSECRET');
    $this->actingAs($f['member']->fresh())->get(route('whats-new'))->assertOk()->assertSee('ZZSECRET');
});

// ── Surface: trending ────────────────────────────────────────────────────────────────────────────────────

it('keeps private club topics out of trending for a non-member', function () {
    $f = leakFixture('private');

    $this->actingAs($f['outsider']->fresh())->get(route('trending.index'))->assertOk()->assertDontSee('ZZSECRET');
});

// ── Surface: REST API ────────────────────────────────────────────────────────────────────────────────────

it('omits a private club from the REST API for a non-member token but includes it for a member', function () {
    $f = leakFixture('private');
    $outToken = app(ApiTokenService::class)->issue($f['outsider'], 'out')['plaintext'];
    $memToken = app(ApiTokenService::class)->issue($f['member'], 'mem')['plaintext'];

    // forums listing
    $outList = $this->withToken($outToken)->getJson('/api/v1/forums')->assertOk()->json('data');
    expect(collect($outList)->pluck('id'))->not->toContain((int) $f['forum']->id);

    // topics + topic endpoints 404 for the non-member, 200 for the member
    $this->withToken($outToken)->getJson('/api/v1/forums/'.$f['forum']->id.'/topics')->assertNotFound();
    $this->withToken($outToken)->getJson('/api/v1/topics/'.$f['topic']->id)->assertNotFound();
    $this->withToken($memToken)->getJson('/api/v1/topics/'.$f['topic']->id)->assertOk();
});

// ── Surface: notifications ───────────────────────────────────────────────────────────────────────────────

it('never delivers a club reply notification to a non-member recipient', function () {
    $f = leakFixture('private');

    // A topic whose OP author is an OUTSIDER (seated via the service-free write path), then a member replies.
    $opByOutsider = app(PostService::class)->createTopic($f['outsider'], $f['forum'], 'Outsider OP', 'markdown', ['source' => 'op body']);
    app(PostService::class)->reply($f['member'], $opByOutsider, 'markdown', ['source' => 'a reply']);

    expect(DB::table('notifications')->where('notifiable_id', $f['outsider']->id)->count())->toBe(0);

    // Sanity: the SAME reply path DOES notify a member OP author.
    $opByMember = app(PostService::class)->createTopic($f['member'], $f['forum'], 'Member OP', 'markdown', ['source' => 'op body']);
    app(PostService::class)->reply($f['owner'], $opByMember, 'markdown', ['source' => 'a reply']);
    expect(DB::table('notifications')->where('notifiable_id', $f['member']->id)->where('type', 'reply')->count())->toBeGreaterThan(0);
});

it('never delivers a reaction notification to a non-member post author', function () {
    $f = leakFixture('private');
    // A post authored by an OUTSIDER (seated via the service-free write path), then a member reacts to it.
    $opByOutsider = app(PostService::class)->createTopic($f['outsider'], $f['forum'], 'Outsider OP', 'markdown', ['source' => 'body']);
    $post = Post::where('topic_id', $opByOutsider->id)->firstOrFail();

    event(new Reacted($f['member'], $post, 'like'));

    expect(DB::table('notifications')->where('notifiable_id', $f['outsider']->id)->where('type', 'reaction')->count())->toBe(0);
});

it('hides a stored club notification from a member who later leaves the club', function () {
    $f = leakFixture('private');
    $t = app(PostService::class)->createTopic($f['member'], $f['forum'], 'MEMBERONLYTITLE thread', 'markdown', ['source' => 'body']);
    app(PostService::class)->reply($f['owner'], $t, 'markdown', ['source' => 'a reply']); // → member gets a stored 'reply' notification

    // While a member, the stored notification renders.
    $this->actingAs($f['member']->fresh())->get(route('notifications.index'))->assertOk()->assertSee('MEMBERONLYTITLE');

    // After leaving the club, the same stored row is filtered out at render (no leak of the snapshot title).
    app(ClubMembershipService::class)->leave($f['club'], $f['member']);
    leakFlush();
    $this->actingAs($f['member']->fresh())->get(route('notifications.index'))->assertOk()->assertDontSee('MEMBERONLYTITLE');
});

// ── Surface: bookmarks ───────────────────────────────────────────────────────────────────────────────────

it('drops a private club bookmark from the saved list for a non-member', function () {
    $f = leakFixture('private');
    Bookmark::create(['user_id' => $f['outsider']->id, 'bookmarkable_type' => Topic::class, 'bookmarkable_id' => $f['topic']->id]);

    $this->actingAs($f['outsider']->fresh())->get(route('saved.index'))->assertOk()->assertDontSee('ZZSECRET');
});

// ── Surface: tags ────────────────────────────────────────────────────────────────────────────────────────

it('keeps a club-exclusive tag off the public tag index', function () {
    $f = leakFixture('private');
    $tag = Tag::create(['name' => 'clubsecrettag', 'slug' => 'clubsecrettag', 'usage_count' => 1]);
    $f['topic']->tags()->attach($tag->id);
    leakFlush();

    $this->get(route('tags.index'))->assertOk()->assertDontSee('clubsecrettag');
});

// ── Surface: webhooks ────────────────────────────────────────────────────────────────────────────────────

it('does not emit outbound webhook events for club discussion', function () {
    // A no-op proof: the subscriber's inClubForum() guard returns true for a club forum, so dispatch is skipped.
    $f = leakFixture('private');
    $subscriber = app(WebhookEventSubscriber::class);
    $ref = new ReflectionMethod($subscriber, 'inClubForum');

    expect($ref->invoke($subscriber, (int) $f['forum']->id))->toBeTrue();
});
