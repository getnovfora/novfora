<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Account;

use App\Community\ReputationService;
use App\Forum\PollService;
use App\Forum\ReactionService;
use App\Messaging\PmAccountCascade;
use App\Models\AclEntry;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\ConversationParticipant;
use App\Models\EmailSuppression;
use App\Models\Message;
use App\Models\PollVote;
use App\Models\Post;
use App\Models\PostDraft;
use App\Models\PostRevision;
use App\Models\Reaction;
use App\Models\RegistrationCheck;
use App\Models\Report;
use App\Models\RoleAssignment;
use App\Models\StaffNote;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserRelationship;
use App\Models\Warning;
use App\Permissions\Scope;
use App\Support\ActorRank;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Account deletion (ADR-0025, Opus xhigh) — the SINGLE audited cascade for both the voluntary (account
 * settings) and admin-forced (staff tools) paths. This is a privacy boundary with denormalised-counter
 * correctness under a hard-delete cascade, so the ORDER is load-bearing and every step runs inside ONE
 * DB::transaction: a mid-cascade failure commits nothing (no half-deleted account, no stale tallies).
 *
 * The governing split (ADR-0025): authored CONTENT is pseudonymised (the attribution pointer → NULL, body
 * kept) so the board and PM threads stay coherent for everyone else; PARTICIPATION metadata (reactions, poll
 * votes, drafts, PM participation, relationships, sessions, in-app notifications, ACL/role grants) is
 * hard-deleted. The PM slice is delegated wholesale to PmAccountCascade — never re-implemented here.
 *
 * Why the work is EXPLICIT rather than leaning on the DB cascade (verified FK reality):
 *   • The user-pointer columns on posts/topics/post_revisions/attachments/reports, audit_log.actor_id,
 *     warnings.issued_by, sessions/registration_checks, and the polymorphic acl_entries/role_assignments/
 *     notifications holders carry NO foreign key — deleting the users row would leave them dangling at a gone
 *     id, never NULL.
 *   • reactions/poll_votes DO cascade-on-delete with the users row, but once that row is gone the affected ids
 *     are lost and the denormalised post_reaction_counts / poll_options.vote_count can no longer be recomputed.
 * So we capture the affected ids first, do ordered explicit work, recount authoritatively, then delete the
 * users row LAST — at which point the real cascadeOnDelete children (notification_preferences, digest_*,
 * group_user, topic_reads, custom_field_values, bans, warnings.user_id, conversation_user, user_relationships)
 * drop as belt-and-braces.
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly PollService $polls,
        private readonly ReputationService $reputation,
    ) {}

    /**
     * Read-only pre-deletion summary shown on BOTH confirm surfaces before any write. (ADR-0025's "tags
     * applied" count is intentionally dropped — taggables carries no per-user attribution column, so it is
     * not derivable.) Posts/topics are counted withTrashed to match what the cascade actually pseudonymises.
     *
     * @return array{posts:int, topics:int, reactions:int, poll_votes:int, messages:int, conversations:int, attachments:int}
     */
    public function summary(User $target): array
    {
        $id = (int) $target->getKey();

        return [
            'posts' => Post::withTrashed()->where('user_id', $id)->count(),
            'topics' => Topic::withTrashed()->where('user_id', $id)->count(),
            'reactions' => Reaction::where('user_id', $id)->count(),
            'poll_votes' => PollVote::where('user_id', $id)->count(),
            'messages' => Message::where('user_id', $id)->count(),
            'conversations' => ConversationParticipant::where('user_id', $id)->count(),
            'attachments' => Attachment::where('user_id', $id)->count(),
        ];
    }

    /**
     * Voluntary deletion. The caller (the ⚡delete-account SFC) has already re-authenticated the password; the
     * service still enforces the one invariant that holds regardless of the caller — the LAST administrator
     * may not delete themselves (it would strand the forum with no admin).
     *
     * @throws AccountDeletionException when $self is the sole remaining administrator
     */
    public function deleteOwnAccount(User $self): void
    {
        if ($this->isSoleAdmin($self)) {
            throw new AccountDeletionException('The last administrator account cannot be deleted.');
        }

        $this->cascade($self, 'self');
    }

    /**
     * Admin-forced deletion. Authorised by bans.manage + the actor-vs-target rank guard, PLUS two
     * deletion-specific guards the rank guard alone does not give (ActorRank lets any admin act on any admin):
     * the target may not be an admin of equal-or-higher rank, and the force path is never a self-delete.
     * Audited identically to the voluntary path.
     */
    public function deleteAccountAsAdmin(User $admin, User $target): void
    {
        abort_unless(self::canForceDelete($admin, $target), 403);

        // Structurally redundant on this path (the acting admin survives), but a cheap belt-and-braces:
        // never let a forced delete strand the forum without an administrator.
        if ($this->isSoleAdmin($target)) {
            throw new AccountDeletionException('The last administrator account cannot be deleted.');
        }

        $this->cascade($target, 'admin');
    }

    /**
     * The full authorisation predicate for the admin-forced path — the SINGLE source of truth, reused by the
     * service entry, the controller gate, and the profile trigger's visibility @if. True only when $admin may
     * force-delete $target.
     */
    public static function canForceDelete(User $admin, User $target): bool
    {
        if ($admin->is($target)) {
            return false; // the force path is not for self-deletion
        }
        if (! $admin->canDo('bans.manage', Scope::global())) {
            return false;
        }
        if (! ActorRank::canActOn($admin, $target)) {
            return false;
        }
        // ActorRank returns true for ANY admin acting on ANY admin; deletion additionally refuses an admin of
        // equal-or-higher rank — you may only force-delete a strictly lower-ranked admin, or a non-admin.
        if ($target->isAdmin() && $target->rankPriority() >= $admin->rankPriority()) {
            return false;
        }

        return true;
    }

    /**
     * Whether removing this user would leave the forum with zero administrators. This is the fast, NON-locking
     * pre-filter — used for the UI "deletion unavailable" signal and a cheap early throw before a transaction is
     * even opened. It is NOT the authority: the TOCTOU window between this read and the delete is closed by
     * assertNotSoleAdminLocked() inside the cascade transaction.
     */
    public function isSoleAdmin(User $user): bool
    {
        return $user->isAdmin()
            && User::whereHas('groups', fn ($q) => $q->where('slug', 'admins'))->count() <= 1;
    }

    /**
     * The AUTHORITATIVE sole-admin guard (A5, apex): a LOCKING current read of the admins-group membership, run
     * inside the deletion transaction so it serialises against any concurrent admin removal. Throws — rolling
     * the transaction back, committing nothing — when removing this user would strand the forum with zero
     * administrators. Admin-ness is re-derived from the locked pivot rows (DB truth), never a stale in-memory
     * model. On drivers without row locks (SQLite) the FOR UPDATE is a no-op, but the in-transaction re-read
     * against live state is still correct; the lock only matters for true MySQL/Postgres concurrency.
     *
     * @throws AccountDeletionException when $userId is the sole remaining administrator
     */
    private function assertNotSoleAdminLocked(int $userId): void
    {
        $adminIds = DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->where('groups.slug', 'admins')
            ->lockForUpdate()
            ->pluck('group_user.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        if (in_array($userId, $adminIds, true) && count($adminIds) <= 1) {
            throw new AccountDeletionException('The last administrator account cannot be deleted.');
        }
    }

    /**
     * The one ordered cascade, shared by both entry points, inside a single transaction.
     *
     * @param  string  $initiatedBy  'self' | 'admin' — recorded in the audit trail
     */
    private function cascade(User $target, string $initiatedBy): void
    {
        $userId = (int) $target->getKey();
        $actorId = auth()->id();   // the acting user (the admin on the forced path; self on the voluntary path)
        $email = $target->email;

        DB::transaction(function () use ($target, $userId, $initiatedBy, $actorId, $email): void {
            // (a0) TOCTOU close (A5, apex): re-assert the sole-admin invariant under a ROW LOCK, inside the
            //      transaction, as the FIRST act — BEFORE any mutation. The non-locking public isSoleAdmin()
            //      checked by the callers is only a fast pre-filter / UI signal; THIS locked re-read is the
            //      authority. Two concurrent admin self-deletions both pass the pre-filter (each still counts
            //      two admins), but the FOR UPDATE on the admins-group pivot serialises them: the first
            //      deletes + commits, the second then reads only ONE admin (itself) and aborts here — so the
            //      forum can never be stranded with zero administrators.
            $this->assertNotSoleAdminLocked($userId);

            // (a) CAPTURE the denormalised-tally inputs BEFORE anything drops the rows that derive them.
            $reactedPostIds = Reaction::where('user_id', $userId)
                ->pluck('post_id')->map(fn ($i): int => (int) $i)->unique()->values()->all();
            $votedOptionIds = PollVote::where('user_id', $userId)
                ->pluck('poll_option_id')->map(fn ($i): int => (int) $i)->unique()->values()->all();

            // (a2) CAPTURE the ids of U's OWN reactions (P2-M5, the ADR-0025 extension) — those reactions
            //      awarded points to OTHER authors, and the rows are about to drop. The AFFECTED-AUTHOR set
            //      is deliberately captured later, AFTER the reaction delete (see c2): deleting the rows
            //      takes their locks, so any in-flight award job is ordered around the cascade (its own
            //      source lock — ReputationService::award — aborts it once the rows are gone), and a
            //      LOCKING current read then sees every award that actually landed, not this transaction's
            //      start snapshot.
            $reactionMorph = (new Reaction)->getMorphClass();
            $userReactionIds = Reaction::where('user_id', $userId)
                ->pluck('id')->map(fn ($i): int => (int) $i)->all();

            // (b) PSEUDONYMISE authored content — anonymise the attribution pointer only, keep the body.
            //     withTrashed() is REQUIRED: posts/topics use SoftDeletes, and the default scope would skip a
            //     soft-deleted row, leaving it pointing at the deleted user (a dangling id + privacy leak).
            Post::withTrashed()->where('user_id', $userId)->update(['user_id' => null]);
            Post::withTrashed()->where('edited_by', $userId)->update(['edited_by' => null]);
            Topic::withTrashed()->where('user_id', $userId)->update(['user_id' => null]);
            Topic::withTrashed()->where('last_post_user_id', $userId)->update(['last_post_user_id' => null]);
            PostRevision::where('editor_id', $userId)->update(['editor_id' => null]);
            Attachment::where('user_id', $userId)->update(['user_id' => null]);
            Report::where('reporter_id', $userId)->update(['reporter_id' => null]);
            Report::where('handled_by', $userId)->update(['handled_by' => null]);
            // Activity-feed actor (P2-M3 addendum) — pseudonymise like the rest of the authored content
            // (activities.actor_id has no FK; verb/subject stay intact, the actor renders "[Deleted]").
            DB::table('activities')->where('actor_id', $userId)->update(['actor_id' => null]);

            // (c) EXPLICIT-DELETE participation, THEN recount authoritatively from the survivors (ids captured
            //     in (a); the recount is absolute, not a delta, so a concurrent reaction by another user is
            //     reconciled correctly without locking).
            Reaction::where('user_id', $userId)->delete();
            $this->reactions->recomputeForPosts($reactedPostIds);

            // (c2) REPUTATION (P2-M5 ⚙, the ADR-0025 extension). Order matters:
            //      1. capture the AFFECTED third-party recipients with a LOCKING current read — now that
            //         the reaction rows are deleted (their locks held by this txn), an in-flight award for
            //         one of them has either committed (visible here) or will abort on its source check;
            //      2. prune the ledger rows SOURCED from U's just-deleted reactions (they awarded OTHERS);
            //      3. prune U's own received rep wholesale (creation awards + reactions TO U's posts — the
            //         surviving reaction rows stay as participation, their ledger rows die with U);
            //      4. recompute the affected third-party authors AUTHORITATIVELY from what survives —
            //         absolute SUMs, mirroring recomputeForPosts; never ±deltas through a half-deleted graph.
            $affectedAuthorIds = [];
            if ($userReactionIds !== []) {
                $affectedAuthorIds = DB::table('reputation_events')
                    ->where('source_type', $reactionMorph)
                    ->whereIn('source_id', $userReactionIds)
                    ->where('user_id', '!=', $userId) // U's own rows die wholesale below; no recompute needed
                    ->lockForUpdate()
                    ->pluck('user_id')->map(fn ($i): int => (int) $i)->unique()->values()->all();

                DB::table('reputation_events')
                    ->where('source_type', $reactionMorph)
                    ->whereIn('source_id', $userReactionIds)
                    ->delete();
            }
            DB::table('reputation_events')->where('user_id', $userId)->delete();
            $this->reputation->recomputeFor($affectedAuthorIds);

            PollVote::where('user_id', $userId)->delete();
            $this->polls->recomputeForOptions($votedOptionIds);

            PostDraft::where('user_id', $userId)->delete(); // private to the owner — no community value

            // (d) PURGE private / PII.
            DB::table('notifications')
                ->where('notifiable_type', $target->getMorphClass())
                ->where('notifiable_id', $userId)
                ->delete();
            DB::table('sessions')->where('user_id', $userId)->delete(); // force-logout every device
            RegistrationCheck::where('user_id', $userId)->delete();
            AclEntry::where('holder_type', 'user')->where('holder_id', $userId)->delete();
            RoleAssignment::where('holder_type', 'user')->where('holder_id', $userId)->delete();

            // warnings.issued_by has no FK → NULL it; warnings.user_id (a real FK) cascades with the row.
            Warning::where('issued_by', $userId)->update(['issued_by' => null]);

            // Staff notes (A1): notes ABOUT this user (staff_notes.user_id) cascade with the row; notes this
            // user AUTHORED about OTHERS survive but are de-identified — author_id has no FK, so NULL it (the
            // note then renders "[Deleted]"), exactly like warnings.issued_by above.
            StaffNote::where('author_id', $userId)->update(['author_id' => null]);

            // Relationship edges (follow + ignore) drop in BOTH directions — who the user followed/ignored
            // AND who followed/ignored them (P2-M5). Both endpoint FKs do cascadeOnDelete with the users row,
            // but the explicit delete keeps the cascade self-contained and testable on any driver.
            UserRelationship::where('user_id', $userId)
                ->orWhere('related_user_id', $userId)
                ->delete();

            // Badge awards die with the account (P2-M5) — same explicit-plus-FK belt-and-braces.
            DB::table('user_badges')->where('user_id', $userId)->delete();

            // email_suppressions is keyed on the address, not the user — delete the user's row(s) so the freed
            // address is deliverable again (recorded decision; see DECISIONS ADR-0025 follow-up).
            if ($email !== '') {
                EmailSuppression::where('email', $email)->delete();
            }

            // (e) PMs — delegate to the binding PM slice (its inner transaction nests as a savepoint).
            app(PmAccountCascade::class)->purge($target);

            // (f) DELETE the users row LAST. The cascadeOnDelete children drop here as belt-and-braces.
            $target->delete();

            // (g) AUDIT the deletion event itself (the target id is now an inert pointer — the row is gone).
            Audit::log('user.deleted', $target, [
                'initiated_by' => $initiatedBy,
                'deleted_user_id' => $userId,
                'by' => $actorId,
            ]);

            // GDPR-consistent erasure of the actor IDENTITY across the user's whole audit trail (the WHAT/
            // action rows remain, de-identified). Run LAST so it also nulls the just-written user.deleted row
            // on the voluntary path (actor == self == userId); on the forced path the admin actor (id ≠ userId)
            // is retained as the security record of who initiated. (Recorded decision.)
            AuditLog::where('actor_id', $userId)->update(['actor_id' => null]);
        });
    }
}
