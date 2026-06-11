<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Account deletion & privacy cascade (ADR-0025, full) — Claude Code kickoff

> Paste the block below into **Claude Code** to build the **full account-deletion feature**. M2 Half-B already
> shipped the **PM slice** of the cascade (`App\Messaging\PmAccountCascade`); this packet builds the **single
> `AccountDeletionService`** that orchestrates the *whole* ADR-0025 cascade (and calls `PmAccountCascade` for
> PMs) plus the **two confirmation surfaces** (voluntary in account settings + admin-forced). This is a
> **privacy boundary + a transactional multi-table cascade with denormalised-counter correctness** — the
> service is **Opus `xhigh`**; the two confirm UIs are Sonnet.
>
> **Binding contract:** [ADR-0025](../../DECISIONS.md#adr-0025--account-deletion-and-content-cascade-policy-2026-06-10)
> — pseudonymise authored content (`user_id → NULL`, body kept), hard-delete participation metadata, owner-
> confirmable, ONE audited service for both paths. Also relevant: ADR-0006 (permission engine), the M2 Half-B
> DECISIONS entry (the PM portion + the anonymisable-author vs cascade-FK split).
>
> **Why now:** the M1 forced-cascade integration tests were explicitly deferred "to when PMs land"; PMs have
> landed, so this closes that out and makes "delete my account" real end-to-end.

---

```
Begin the full account-deletion feature (ADR-0025). PMs already have App\Messaging\PmAccountCascade — this
builds the SINGLE AccountDeletionService that runs the whole cascade (and calls PmAccountCascade for the PM
slice) + the voluntary (account-settings) and admin-forced (ACP) confirmation flows. This is a privacy
boundary with denormalised-counter correctness under a hard-delete cascade — get the transaction order and the
recounts right over getting it fast. Opus xhigh on the service + the cascade order + the recounts + the rank
guard; Sonnet on the two confirm UIs.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, and DECISIONS.md ADR-0025 IN FULL (it is the binding
contract). Read app/Messaging/PmAccountCascade.php (the PM slice this orchestrates + the model to mirror),
app/AntiSpam/SpamCleaner.php (the closest existing transactional bulk action — mirror its DB::transaction +
Audit shape; deletion is the more-final adjacent op), app/Support/ActorRank.php (the actor-vs-target rank
guard), app/Support/Audit.php (Audit::log signature), and the recount services app/Forum/ReactionService.php,
PollService.php, TagService.php. Branch claude/p2-account-deletion off main AFTER M2 Half-B has merged. Commit
identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before
starting.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: the AccountDeletionService transaction (capture-affected-ids BEFORE the
    cascade drops them; pseudonymise authored content; explicit-delete participation + authoritative recount;
    purge private/PII; call PmAccountCascade; delete the users row LAST), the recount correctness, and the
    admin-forced rank guard (an admin must not force-delete a same-or-higher-ranked admin; no self-delete via
    the forced path).
  • Sonnet 4.6 — the ⚡delete-account settings SFC and the admin-forced action surface, once the service +
    summary are settled.

KEY FK REALITY (verified — drives the cascade design):
  • AUTO-CASCADE (real cascadeOnDelete FK on user_id → drop when the users row is deleted): reactions,
    poll_votes, post_drafts, notification_preferences, digest_queue_items, digest_runs, digest_preferences,
    group_user, topic_reads, custom_field_values, bans, warnings.user_id, conversation_user,
    user_relationships. (bounce_reviews.reviewed_by is nullOnDelete.)
  • APP-LAYER (raw nullable column, NO FK — MUST be handled in code, else dangling id): posts.user_id +
    posts.edited_by, topics.user_id + topics.last_post_user_id, post_revisions.editor_id, attachments.user_id,
    reports.reporter_id + reports.handled_by, audit_log.actor_id, warnings.issued_by, sessions.user_id,
    registration_checks.user_id, acl_entries.holder_id (poly, type=user), role_assignments.holder_id (poly),
    notifications.notifiable_id (morphs), conversations.created_by + messages.user_id (PmAccountCascade).
  CONSEQUENCE: relying on the DB cascade alone is WRONG for two reasons — (a) the no-FK columns never null
  themselves, and (b) reactions/poll_votes auto-drop when the users row goes, AFTER which you can't recount
  the denormalised tallies because you've lost the affected ids. So the service does EXPLICIT, ordered work
  inside one transaction and deletes the users row last; the DB cascade is belt-and-braces.

BUILD:

1) RECOUNT SEAMS (⚙ — the correctness gap): ReactionService::recountType, PollService::recountOption, and
   TagService::recount are PRIVATE with no public batch API. Add small public batch methods that reuse the
   existing private logic — ReactionService::recomputeForPosts(array $postIds): void (+ invalidateTopic for
   each affected topic), PollService::recomputeForOptions(array $optionIds): void (+ its version bump). Tags
   need NO recount here (topics are pseudonymised, not deleted, so taggables — which has NO user column — is
   untouched); do NOT add a tag path unless topics are hard-deleted (they are not).

2) [Deleted] RENDER (◐): ADR-0025 says a pseudonymised author renders as "[Deleted]" + the guest avatar.
   Today x-ui.user-name falls back to "unknown" and x-ui.avatar to "User". Make a NULL author at content/
   message sites render "[Deleted]" (the cleanest: a dedicated fallback the author partials pass, e.g.
   <x-ui.user-name :user="$author" :fallback="__('[Deleted]')" /> and the guest/default avatar), WITHOUT
   changing the generic default for non-author uses (e.g. a missing optional user elsewhere). /users/{user}
   already 404s for a hard-deleted user (route-model binding) — keep it; add a test.

3) AccountDeletionService (⚙ — app/Account/AccountDeletionService.php). ONE public entry per path, ONE shared
   private cascade, all inside a single DB::transaction:
   • summary(User $target): array — READ-ONLY pre-deletion counts shown before any write: posts, topics,
     reactions given, poll votes cast, PMs/conversations, attachments. (NOTE: drop ADR-0025's "tags applied"
     count — taggables has no per-user attribution column, so it is not derivable; use the list above.)
   • deleteOwnAccount(User $self): void — voluntary; the caller (the SFC) has re-authenticated the password.
   • deleteAccountAsAdmin(User $admin, User $target): void — admin-forced; asserts bans.manage AND
     ActorRank::canActOn($admin, $target) AND — additional guard — NOT ($target is an admin of rank ≥ admin's)
     and $target !== $admin (force path is not for self). Audited.
   • private cascade(User $target, string $initiatedBy): void — the binding order, in ONE transaction:
       a. CAPTURE affected ids BEFORE deleting anything: reactedPostIds (+ their topic ids), votedOptionIds.
       b. PSEUDONYMISE authored content (UPDATE … SET user_id = NULL): posts (user_id, edited_by), topics
          (user_id, last_post_user_id), post_revisions (editor_id), attachments (user_id), reports
          (reporter_id, handled_by). Body/edit-history/search-index untouched — only the attribution pointer.
       c. EXPLICIT-DELETE participation, then RECOUNT: reactions WHERE user_id → recomputeForPosts;
          poll_votes WHERE user_id → recomputeForOptions; post_drafts WHERE user_id (private; delete).
       d. PURGE private/PII: notifications (notifiable = User#id), sessions WHERE user_id (force logout),
          registration_checks WHERE user_id, acl_entries/role_assignments WHERE holder=user. (The
          cascadeOnDelete tables — notification_preferences, digest_*, group_user, topic_reads,
          custom_field_values, bans, warnings.user_id — drop with the users row; warnings.issued_by → NULL.)
       e. PMs: app(PmAccountCascade::class)->purge($target) (messages pseudonymised, conversation_user
          hard-deleted, empty conversations purged, conversations.created_by NULL, user_relationships both
          endpoints). Do NOT re-implement — call it.
       f. DELETE the users row LAST.
       g. Audit::log('user.deleted', $target, ['initiated_by' => $initiatedBy, 'by' => $actorId]).
   FLAG (decide explicitly, record in DECISIONS): audit_log.actor_id — pseudonymise to NULL (GDPR-consistent
   erasure of the actor identity while the audited WHAT/action rows remain) vs retain (security trail). Same
   call for email_suppressions keyed on the user's email (delete to free the address vs retain the
   suppression). Recommend: NULL the audit actor; delete the user's email_suppressions row(s).

4) QUEUED-JOB NO-OP: already satisfied — SendReactionNotification / SendPmNotification set
   $deleteWhenMissingModels = true and null-check User::find before notifying. Add NO new code; ADD a test
   asserting a queued notification whose target was just deleted no-ops (never throws).

5) UI — VOLUNTARY (Sonnet): a new "Account" tab in resources/views/components/settings/shell.blade.php →
   /settings/account (settings.account, auth+verified) → resources/views/settings/account.blade.php hosting
   ⚡delete-account (mirror ⚡notification-preferences: own-only, no user-id param, auth asserted in mount()
   AND every action). TWO-STEP: (1) initiate → show summary() of what will be permanently removed + require
   PASSWORD re-authentication (Hash::check) + an explicit typed/checkbox confirmation; (2) confirm → call
   deleteOwnAccount, then Auth::logout + invalidate the session and redirect home with a flash. A staff/admin
   account uses the same path but the flow notes any sole-admin guard (do not allow deleting the LAST admin —
   flag + block).

6) UI — ADMIN-FORCED (Sonnet ◐): there is NO per-member ACP page today; mirror the SpamCleaner trigger
   surface (BanController::spamClean — POST /users/{user}/spam-clean, gated bans.manage + ActorRank). Add the
   admin-forced delete alongside it: a controller action (e.g. DELETE /users/{user}, gated bans.manage +
   ActorRank + the same-or-higher-admin guard) that shows the SAME summary() + an explicit confirm, then calls
   deleteAccountAsAdmin. Surface the trigger wherever spam-clean is triggered (the staff tools on the user
   profile / moderation surface). Audited identically.

DEFINITION OF DONE (binding): AccountDeletionService forced-cascade tests — authored content pseudonymised
(posts/topics/revisions/attachments/reports user-pointers NULL, bodies intact); reactions/poll_votes
hard-deleted AND post_reaction_counts / poll_options.vote_count RECOUNTED correct; post_drafts gone;
notifications + sessions + registration_checks + the user's acl/role holder rows purged; PM slice delegated to
PmAccountCascade (assert it ran: messages pseudonymised, conversation_user gone, empty convos purged,
relationships both endpoints); cascadeOnDelete tables empty for the user; users row gone; warnings.issued_by /
audit actor handled per the recorded decision. Confirmation-flow tests: voluntary requires correct password
(wrong password → no deletion); admin-forced requires bans.manage + outranking (a mod cannot delete; an admin
cannot force-delete a higher/equal admin or self; last-admin guard blocks). [Deleted] render test + profile
404 test. Queued-job-no-op test. Whole cascade is ONE transaction (a mid-cascade failure commits nothing).
Dusk journey (voluntary delete from settings → logged out → /users/{old id} 404; a pseudonymised post shows
"[Deleted]") + light·dark × mobile·desktop screenshots. Pint / Larastan L5 / composer+npm audit / assets-fresh
green; full suite green; PROJECT-STATE + DECISIONS updated. Small conventional DCO commits, one logical change
each.

SCOPE FENCE — the account-deletion service + the two confirm flows + the recount seams + [Deleted] render +
their tests ONLY. NOT here: a full ACP member-LIST/detail page (build only the minimal admin-forced trigger;
a richer member-management ACP is its own packet); GDPR data-EXPORT / "download my data" (separate); a
soft-delete/grace-period/undo window (this is a hard, immediate, confirmed deletion — flag if a grace period
is wanted and stop); reputation/badges (Should-tier, not built). Reuse PmAccountCascade — do NOT fork it. No
second permission path; authorize only via canDo / the policies / ActorRank. Reversible migration only if any
column needs adjusting (none expected). Strict clean-room. If a needed mechanism isn't in the engine, FLAG it.
When it lands runnable + tested, report back here.
```

---

## When account-deletion reports back

Cowork reviews (adversarially, privacy/security-critical): (1) is the WHOLE cascade one **transaction** —
does a forced failure mid-cascade commit *nothing* (no half-deleted account, no stale counts)? (2) are the
**denormalised counters** (`post_reaction_counts`, `poll_options.vote_count`) provably correct after the batch
delete — were the affected ids captured **before** the rows dropped? (3) does authored content **survive,
pseudonymised** (bodies intact, `user_id`/`edited_by`/etc → NULL), and does participation metadata
**hard-delete**? (4) does the voluntary path truly **re-authenticate** (wrong password ⇒ nothing happens) and
the admin path enforce **bans.manage + outranking + no-higher/equal-admin + no-self + last-admin** guards?
(5) is the PM slice **delegated to `PmAccountCascade`** (not re-implemented or diverged)? (6) does a
pseudonymised author render **`[Deleted]`** and the profile **404**, and do queued notifications targeting the
deleted user **no-op**? Then update PROJECT-STATE.

## After this

- **GDPR data-export** ("download my data") — the complementary right; its own packet.
- **ACP member-management page** (list + detail + the moderation actions in one place) — folds the forced-delete
  trigger, ban, warn, spam-clean, role-change into a proper member surface.
- **Optional grace-period / soft-delete + undo** for voluntary deletion (if product wants a cooling-off window)
  — a deliberate change to the "immediate hard delete" model decided here.
