<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionException;
use App\Account\AccountDeletionService;
use App\Events\Reacted;
use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Listeners\SendReactionNotification;
use App\Messaging\PmAccountCascade;
use App\Models\AclEntry;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\EmailSuppression;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Message;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Post;
use App\Models\PostDraft;
use App\Models\PostReactionCount;
use App\Models\PostRevision;
use App\Models\Reaction;
use App\Models\RegistrationCheck;
use App\Models\Report;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserRelationship;
use App\Models\Warning;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Account deletion (ADR-0025): the single audited cascade for the voluntary + admin-forced paths. Content is
| pseudonymised (attribution → NULL, body kept); participation metadata is hard-deleted; denormalised tallies
| are recounted authoritatively; the whole thing is one transaction; the PM slice is delegated to
| PmAccountCascade. These tests are the M1-deferred forced-cascade integration tests, now that PMs have landed.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function delForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

function grantBansManage(User $user): void
{
    AclEntry::create([
        'permission_key' => 'bans.manage',
        'holder_type' => 'user',
        'holder_id' => $user->getKey(),
        'scope_type' => 'global',
        'scope_id' => null,
        'value' => 1, // PermissionValue::Allow
    ]);
    app(PermissionResolver::class)->flushMemo();
}

it('runs the full forced cascade — pseudonymises content, hard-deletes participation, recounts, purges PII, delegates PMs, removes the row', function () {
    $forum = delForum();
    $admin = Users::inGroups(['admins']);
    grantBansManage($admin);

    $target = Users::inGroups(['members', 'tl1'], ['email' => 'target@del.test']);
    $other = Users::inGroups(['members', 'tl1']);
    $other2 = Users::inGroups(['members', 'tl1']);
    $targetId = (int) $target->id;

    // ── authored content (to be pseudonymised) ────────────────────────────────────────────────────
    $topic = app(PostService::class)->createTopic($target, $forum, 'Targets topic', 'tiptap_json', Content::doc('original body'));
    $topic->forceFill(['last_post_user_id' => $targetId])->saveQuietly();
    $openingPost = $topic->posts()->firstOrFail();
    $openingPostId = (int) $openingPost->id;
    $openingBody = $openingPost->body_text;

    $otherTopic = app(PostService::class)->createTopic($other, $forum, 'Others topic', 'tiptap_json', Content::doc('op'));
    $otherPost = $otherTopic->posts()->firstOrFail();
    $otherPost->forceFill(['edited_by' => $targetId])->saveQuietly();

    $revision = PostRevision::create(['post_id' => $otherPost->id, 'editor_id' => $targetId, 'body_canonical' => Content::doc('rev')]);
    $attachment = Attachment::create(['user_id' => $targetId, 'post_id' => $otherPost->id, 'path' => 'a/b.png', 'original_name' => 'b.png', 'mime' => 'image/png']);
    $reportByTarget = Report::create(['reportable_type' => Post::class, 'reportable_id' => $otherPost->id, 'reporter_id' => $targetId]);
    $reportHandledByTarget = Report::create(['reportable_type' => Post::class, 'reportable_id' => $otherPost->id, 'reporter_id' => $other->id, 'handled_by' => $targetId, 'status' => 'resolved']);

    // ── reactions (hard-delete + authoritative recount) ───────────────────────────────────────────
    app(ReactionService::class)->toggle($target, $otherPost, 'like');
    app(ReactionService::class)->toggle($other2, $otherPost, 'like'); // shared post: like => 2
    $soloPost = app(PostService::class)->reply($other, $otherTopic, 'tiptap_json', Content::doc('solo'));
    app(ReactionService::class)->toggle($target, $soloPost, 'like'); // solo post: like => 1 (only target)

    // ── poll votes (hard-delete + authoritative recount) ──────────────────────────────────────────
    $poll = app(PollService::class)->createPoll($other, $otherTopic, 'Pick one', ['A', 'B']);
    $optA = (int) $poll->options[0]->id;
    app(PollService::class)->vote($target, $poll, [$optA]);
    app(PollService::class)->vote($other2, $poll, [$optA]); // option A => 2
    $poll2Topic = app(PostService::class)->createTopic($other, $forum, 'Poll two', 'tiptap_json', Content::doc('op2'));
    $poll2 = app(PollService::class)->createPoll($other, $poll2Topic, 'Pick again', ['X', 'Y']);
    $optX = (int) $poll2->options[0]->id;
    app(PollService::class)->vote($target, $poll2, [$optX]); // option X => 1 (only target)

    // ── private / PII ─────────────────────────────────────────────────────────────────────────────
    PostDraft::create(['user_id' => $targetId, 'context_type' => 'reply', 'context_id' => $topic->id, 'body_canonical' => Content::doc('draft')]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Test',
        'notifiable_type' => $target->getMorphClass(), 'notifiable_id' => $targetId,
        'data' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $targetId, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);
    RegistrationCheck::create(['user_id' => $targetId, 'decision' => 'allow', 'ip_address' => '1.2.3.4', 'email' => $target->email]);
    AclEntry::create(['permission_key' => 'post.create', 'holder_type' => 'user', 'holder_id' => $targetId, 'scope_type' => 'global', 'scope_id' => null, 'value' => 1]);
    $role = Role::first() ?? Role::create(['slug' => 'tmp-role', 'name' => 'Tmp', 'is_preset' => false]);
    RoleAssignment::create(['role_id' => $role->id, 'holder_type' => 'user', 'holder_id' => $targetId, 'scope_type' => 'global', 'scope_id' => null]);
    EmailSuppression::create(['email' => $target->email, 'reason' => 'bounce']);

    // ── warnings + audit (NULL the actor pointers) ────────────────────────────────────────────────
    $warningIssuedByTarget = Warning::create(['user_id' => $other->id, 'issued_by' => $targetId, 'reason' => 'issued by target']);
    $warningAgainstTarget = Warning::create(['user_id' => $targetId, 'issued_by' => $other->id, 'reason' => 'against target']);
    $priorAudit = AuditLog::create(['actor_id' => $targetId, 'action' => 'post.created', 'created_at' => now()]);

    // ── cascadeOnDelete belt-and-braces witnesses ─────────────────────────────────────────────────
    Ban::create(['user_id' => $targetId, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x']);
    // group_user rows already exist (target is in members + tl1)

    // ── PM slice (must be delegated to PmAccountCascade) ───────────────────────────────────────────
    $conv = Conversation::factory()->create(['created_by' => $targetId]);
    ConversationParticipant::factory()->create(['conversation_id' => $conv->id, 'user_id' => $targetId]);
    ConversationParticipant::factory()->create(['conversation_id' => $conv->id, 'user_id' => $other->id]);
    $pmMessage = Message::factory()->create(['conversation_id' => $conv->id, 'user_id' => $targetId]);
    $soloConv = Conversation::factory()->create(['created_by' => $targetId]);
    ConversationParticipant::factory()->create(['conversation_id' => $soloConv->id, 'user_id' => $targetId]);
    Message::factory()->create(['conversation_id' => $soloConv->id, 'user_id' => $targetId]);
    UserRelationship::factory()->ignore()->create(['user_id' => $targetId, 'related_user_id' => $other->id]);

    // ════ ACT ════
    $this->actingAs($admin);
    app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $target);

    // ════ ASSERT ════
    // users row gone
    expect(User::find($targetId))->toBeNull();

    // authored content pseudonymised; body intact
    expect(Topic::withTrashed()->find($topic->id)->user_id)->toBeNull()
        ->and(Topic::withTrashed()->find($topic->id)->last_post_user_id)->toBeNull()
        ->and(Post::withTrashed()->find($openingPostId)->user_id)->toBeNull()
        ->and(Post::withTrashed()->find($openingPostId)->body_text)->toBe($openingBody)
        ->and($otherPost->fresh()->edited_by)->toBeNull()
        ->and(PostRevision::find($revision->id)->editor_id)->toBeNull()
        ->and(Attachment::find($attachment->id)->user_id)->toBeNull()
        ->and(Report::find($reportByTarget->id)->reporter_id)->toBeNull()
        ->and(Report::find($reportHandledByTarget->id)->handled_by)->toBeNull();

    // reactions hard-deleted + recounted (shared kept at 1; solo dropped to 0 → row removed)
    expect(Reaction::where('user_id', $targetId)->count())->toBe(0)
        ->and((int) PostReactionCount::where('post_id', $otherPost->id)->where('type', 'like')->value('count'))->toBe(1)
        ->and(PostReactionCount::where('post_id', $soloPost->id)->where('type', 'like')->exists())->toBeFalse();

    // poll votes hard-deleted + recounted (A kept at 1; X dropped to 0)
    expect(PollVote::where('user_id', $targetId)->count())->toBe(0)
        ->and((int) PollOption::find($optA)->vote_count)->toBe(1)
        ->and((int) PollOption::find($optX)->vote_count)->toBe(0);

    // drafts gone
    expect(PostDraft::where('user_id', $targetId)->count())->toBe(0);

    // PII purged
    expect(DB::table('notifications')->where('notifiable_id', $targetId)->where('notifiable_type', $target->getMorphClass())->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $targetId)->count())->toBe(0)
        ->and(RegistrationCheck::where('user_id', $targetId)->count())->toBe(0)
        ->and(AclEntry::where('holder_type', 'user')->where('holder_id', $targetId)->count())->toBe(0)
        ->and(RoleAssignment::where('holder_type', 'user')->where('holder_id', $targetId)->count())->toBe(0)
        ->and(EmailSuppression::where('email', 'target@del.test')->count())->toBe(0);

    // warnings.issued_by NULLed; the warning AGAINST the user cascaded away with the row
    expect(Warning::find($warningIssuedByTarget->id)->issued_by)->toBeNull()
        ->and(Warning::find($warningAgainstTarget->id))->toBeNull();

    // audit: prior actor trail de-identified; deletion event recorded with the admin actor retained
    expect(AuditLog::find($priorAudit->id)->actor_id)->toBeNull();
    $deletionAudit = AuditLog::where('action', 'user.deleted')->firstOrFail();
    $changes = is_string($deletionAudit->changes) ? json_decode($deletionAudit->changes, true) : $deletionAudit->changes;
    expect((int) $deletionAudit->actor_id)->toBe((int) $admin->id)
        ->and($changes['initiated_by'])->toBe('admin')
        ->and((int) $changes['deleted_user_id'])->toBe($targetId);

    // cascadeOnDelete witnesses gone (proves the users row truly hard-deleted)
    expect(DB::table('group_user')->where('user_id', $targetId)->count())->toBe(0)
        ->and(Ban::where('user_id', $targetId)->count())->toBe(0);

    // PM slice delegated to PmAccountCascade
    expect(Message::find($pmMessage->id)->user_id)->toBeNull()
        ->and(Conversation::find($conv->id))->not->toBeNull()
        ->and(Conversation::find($conv->id)->created_by)->toBeNull()
        ->and(ConversationParticipant::where('conversation_id', $conv->id)->where('user_id', $targetId)->count())->toBe(0)
        ->and(ConversationParticipant::where('conversation_id', $conv->id)->where('user_id', $other->id)->count())->toBe(1)
        ->and(Conversation::find($soloConv->id))->toBeNull()
        ->and(UserRelationship::where('user_id', $targetId)->orWhere('related_user_id', $targetId)->count())->toBe(0);
});

it('rolls the WHOLE cascade back on a mid-cascade failure — nothing commits', function () {
    $forum = delForum();
    $admin = Users::inGroups(['admins']);
    grantBansManage($admin);
    $target = Users::inGroups(['members', 'tl1']);
    $other = Users::inGroups(['members', 'tl1']);
    $targetId = (int) $target->id;

    $topic = app(PostService::class)->createTopic($target, $forum, 'T', 'tiptap_json', Content::doc('body'));
    $otherTopic = app(PostService::class)->createTopic($other, $forum, 'O', 'tiptap_json', Content::doc('op'));
    $otherPost = $otherTopic->posts()->firstOrFail();
    app(ReactionService::class)->toggle($target, $otherPost, 'like');

    // Force a failure at the PM step (which runs AFTER pseudonymise + the participation deletes).
    $this->app->bind(PmAccountCascade::class, fn () => new class
    {
        public function purge(User $user): void
        {
            throw new RuntimeException('mid-cascade boom');
        }
    });

    $this->actingAs($admin);
    expect(fn () => app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $target))
        ->toThrow(RuntimeException::class);

    // Everything must be intact — the transaction rolled back.
    expect(User::find($targetId))->not->toBeNull()
        ->and(Topic::withTrashed()->find($topic->id)->user_id)->toBe($targetId)
        ->and(Reaction::where('user_id', $targetId)->where('post_id', $otherPost->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'user.deleted')->count())->toBe(0);
});

it('forbids a moderator from force-deleting an admin (rank guard)', function () {
    delForum();
    $mod = Users::inGroups(['moderators']);
    grantBansManage($mod);
    $adminTarget = Users::inGroups(['admins']);

    expect(AccountDeletionService::canForceDelete($mod, $adminTarget))->toBeFalse();

    $this->actingAs($mod);
    expect(fn () => app(AccountDeletionService::class)->deleteAccountAsAdmin($mod, $adminTarget))
        ->toThrow(HttpException::class);
    expect(User::find($adminTarget->id))->not->toBeNull();
});

it('forbids an admin from force-deleting an equal-or-higher admin', function () {
    delForum();
    $admin1 = Users::inGroups(['admins']);
    grantBansManage($admin1);
    $admin2 = Users::inGroups(['admins']);

    expect(AccountDeletionService::canForceDelete($admin1, $admin2))->toBeFalse();

    $this->actingAs($admin1);
    expect(fn () => app(AccountDeletionService::class)->deleteAccountAsAdmin($admin1, $admin2))
        ->toThrow(HttpException::class);
    expect(User::find($admin2->id))->not->toBeNull();
});

it('forbids the admin-forced path from deleting oneself', function () {
    delForum();
    $admin = Users::inGroups(['admins']);
    grantBansManage($admin);

    expect(AccountDeletionService::canForceDelete($admin, $admin))->toBeFalse();

    $this->actingAs($admin);
    expect(fn () => app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $admin))
        ->toThrow(HttpException::class);
    expect(User::find($admin->id))->not->toBeNull();
});

it('blocks deletion of the last administrator account', function () {
    delForum();
    $soleAdmin = Users::inGroups(['admins']);

    expect(app(AccountDeletionService::class)->isSoleAdmin($soleAdmin))->toBeTrue();

    $this->actingAs($soleAdmin);
    expect(fn () => app(AccountDeletionService::class)->deleteOwnAccount($soleAdmin))
        ->toThrow(AccountDeletionException::class);
    expect(User::find($soleAdmin->id))->not->toBeNull();
});

it('allows an administrator to delete their own account when another admin remains', function () {
    delForum();
    $admin1 = Users::inGroups(['admins']);
    $admin2 = Users::inGroups(['admins']);

    expect(app(AccountDeletionService::class)->isSoleAdmin($admin1))->toBeFalse();

    $this->actingAs($admin1);
    app(AccountDeletionService::class)->deleteOwnAccount($admin1);

    expect(User::find($admin1->id))->toBeNull()
        ->and(User::find($admin2->id))->not->toBeNull();

    // Voluntary path nulls the actor identity on the deletion record itself (actor == self).
    $audit = AuditLog::where('action', 'user.deleted')->firstOrFail();
    expect($audit->actor_id)->toBeNull();
    $changes = is_string($audit->changes) ? json_decode($audit->changes, true) : $audit->changes;
    expect($changes['initiated_by'])->toBe('self');
});

it('closes the sole-admin TOCTOU: the in-transaction locked guard blocks even when the pre-check is stale (A5)', function () {
    delForum();

    // Reproduce the TOCTOU staleness deterministically. Create the user as a NON-admin and load their groups,
    // so the in-memory set read by isAdmin() is cached WITHOUT admins...
    $user = Users::inGroups(['members']);
    $user->load('groups');

    // ...then make them the SOLE administrator directly in the DB, WITHOUT refreshing the model. The fast,
    // NON-locking pre-check now reads the stale model and returns false (exactly what a check-then-act window
    // produces), so deleteOwnAccount proceeds past it — but the authoritative locked re-check inside cascade()
    // reads LIVE DB state, sees the lone administrator, and aborts. The forum is never stranded.
    $adminGroupId = Group::where('slug', 'admins')->value('id');
    DB::table('group_user')->insert(['user_id' => $user->id, 'group_id' => $adminGroupId, 'is_primary' => false]);

    expect($user->isAdmin())->toBeFalse()                                       // stale in-memory view
        ->and(app(AccountDeletionService::class)->isSoleAdmin($user))->toBeFalse(); // pre-filter is fooled

    $this->actingAs($user);
    expect(fn () => app(AccountDeletionService::class)->deleteOwnAccount($user))
        ->toThrow(AccountDeletionException::class);
    expect(User::find($user->id))->not->toBeNull(); // not stranded — the locked guard caught it
});

it('still allows the genuine last-of-two admin deletion to complete through the locked guard (A5)', function () {
    delForum();
    $admin1 = Users::inGroups(['admins']);
    $admin2 = Users::inGroups(['admins']);

    // admin1 deletes first — the locked guard sees two admins, permits it.
    $this->actingAs($admin1);
    app(AccountDeletionService::class)->deleteOwnAccount($admin1);
    expect(User::find($admin1->id))->toBeNull();

    // admin2 is now genuinely the sole admin — the locked guard (and the pre-filter) both refuse.
    $this->actingAs($admin2);
    expect(fn () => app(AccountDeletionService::class)->deleteOwnAccount($admin2))
        ->toThrow(AccountDeletionException::class);
    expect(User::find($admin2->id))->not->toBeNull();
});

/** Bootstrap a co-owner the way the installer crowns one: admins membership + is_co_owner flag + security grant. */
function delCoOwner(): User
{
    $adminsId = (int) Group::where('slug', 'admins')->value('id');
    $u = Users::inGroups(['admins']);
    $u->groups()->updateExistingPivot($adminsId, ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $u->id,
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $u->fresh();
}

it('blocks deleting the last co-owner even when other admins remain (A5 co-owner, ADR-0080)', function () {
    delForum();
    Users::inGroups(['admins']); // a second PLAIN admin — so the sole-ADMIN guard does NOT fire…
    $soleCo = delCoOwner();      // …but this is the only CO-OWNER.

    expect(app(AccountDeletionService::class)->isSoleAdmin($soleCo))->toBeFalse();
    expect(app(AccountDeletionService::class)->isSoleCoOwner($soleCo))->toBeTrue();

    $this->actingAs($soleCo);
    expect(fn () => app(AccountDeletionService::class)->deleteOwnAccount($soleCo))
        ->toThrow(AccountDeletionException::class);
    expect(User::find($soleCo->id))->not->toBeNull(); // not stranded — the Security tier survives
});

it('allows deleting a co-owner when another co-owner remains (A5 co-owner)', function () {
    delForum();
    $coA = delCoOwner();
    $coB = delCoOwner();

    expect(app(AccountDeletionService::class)->isSoleCoOwner($coA))->toBeFalse();

    $this->actingAs($coA);
    app(AccountDeletionService::class)->deleteOwnAccount($coA);

    expect(User::find($coA->id))->toBeNull()->and(User::find($coB->id))->not->toBeNull();
});

it('summarises the pre-deletion footprint', function () {
    $forum = delForum();
    $u = Users::inGroups(['members', 'tl1']);
    $other = Users::inGroups(['members', 'tl1']);

    app(PostService::class)->createTopic($u, $forum, 'Mine', 'tiptap_json', Content::doc('b'));
    $otherTopic = app(PostService::class)->createTopic($other, $forum, 'Theirs', 'tiptap_json', Content::doc('o'));
    app(ReactionService::class)->toggle($u, $otherTopic->posts()->firstOrFail(), 'like');

    $summary = app(AccountDeletionService::class)->summary($u);

    expect($summary['posts'])->toBe(1)
        ->and($summary['topics'])->toBe(1)
        ->and($summary['reactions'])->toBe(1)
        ->and($summary['poll_votes'])->toBe(0)
        ->and($summary['messages'])->toBe(0)
        ->and($summary['conversations'])->toBe(0)
        ->and($summary['attachments'])->toBe(0);
});

it('renders [Deleted] for a pseudonymised post author and 404s the gone profile', function () {
    $forum = delForum();
    $author = Users::inGroups(['members', 'tl1'], ['display_name' => 'OriginalAuthor', 'username' => 'origauthor']);
    $viewer = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'tiptap_json', Content::doc('the body text'));
    $authorId = (int) $author->id;

    // Pseudonymise as the cascade would, then hard-delete the row.
    Post::withTrashed()->where('user_id', $authorId)->update(['user_id' => null]);
    Topic::withTrashed()->where('user_id', $authorId)->update(['user_id' => null, 'last_post_user_id' => null]);

    $this->actingAs($viewer)->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('[Deleted]')
        ->assertDontSee('OriginalAuthor');

    User::find($authorId)->delete();
    $this->actingAs($viewer)->get(route('profiles.show', $authorId))->assertNotFound();
});

it('no-ops a queued reaction notification whose target was just deleted', function () {
    $forum = delForum();
    $author = Users::inGroups(['members', 'tl1']);
    $reactor = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('body'));
    $post = $topic->posts()->firstOrFail();

    // Pseudonymise the author (as deletion would) BEFORE the queued listener runs.
    Post::withTrashed()->where('user_id', $author->id)->update(['user_id' => null]);

    $before = DB::table('notifications')->count();
    $listener = app(SendReactionNotification::class);

    expect(fn () => $listener->handle(new Reacted($reactor, $post->fresh(), 'like')))->not->toThrow(Exception::class);
    expect(DB::table('notifications')->count())->toBe($before);
});

it('pseudonymises the deleted user\'s activity-feed rows (P2-M3 addendum), leaving others intact', function () {
    delForum();
    $admin = Users::inGroups(['admins']);
    grantBansManage($admin);
    $target = Users::inGroups(['members', 'tl1']);
    $other = Users::inGroups(['members', 'tl1']);

    $targetAct = Activity::factory()->by((int) $target->id)->create();
    $otherAct = Activity::factory()->by((int) $other->id)->create();

    $this->actingAs($admin);
    app(AccountDeletionService::class)->deleteAccountAsAdmin($admin, $target);

    expect(Activity::find($targetAct->id)->actor_id)->toBeNull()              // actor pseudonymised
        ->and(Activity::find($targetAct->id)->verb)->toBe($targetAct->verb)   // verb/subject intact
        ->and((int) Activity::find($targetAct->id)->subject_id)->toBe((int) $targetAct->subject_id)
        ->and((int) Activity::find($otherAct->id)->actor_id)->toBe((int) $other->id); // others untouched
});
