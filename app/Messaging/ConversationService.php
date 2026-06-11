<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Messaging;

use App\AntiSpam\ContentModerator;
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\PmRateLimiter;
use App\Content\ContentRenderer;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Models\UserRelationship;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The write path for private messages / conversations (P2-M2 Half-B, Opus xhigh). This is the SINGLE place the
 * PM anti-spam spine is enforced — at the SERVICE layer, not just the UI:
 *   • pm.send (TL0 = NEVER mass-PM hard gate) is re-checked on EVERY send, so a demotion to TL0 stops a user
 *     mid-conversation, not only at the inbox door;
 *   • PmRateLimiter caps the per-trust send rate;
 *   • the mass-PM recipient cap bounds blast radius at start AND at add-to-conversation;
 *   • the recipient IGNORE check is enforced at BOTH start (silent exclusion — block semantics) and invite;
 *   • message bodies render through the SAME ContentRenderer + ContentModerator path as posts (no second
 *     sanitizer), and a REJECT verdict aborts the write inside the transaction (no orphan conversation).
 * Participant-only ACCESS is ConversationPolicy's job; this service re-asserts participation as
 * defence-in-depth so a forged Livewire action cannot bypass it.
 */
final class ConversationService
{
    public function __construct(
        private readonly ContentRenderer $renderer,
        private readonly ContentModerator $moderator,
        private readonly PmRateLimiter $rateLimiter,
    ) {}

    /**
     * Start a new conversation and post its opening message. Recipients who ignore the sender are silently
     * excluded (block semantics — the sender is never told who ignores them).
     *
     * @param  array<int,int>  $recipientIds
     * @param  array<string,mixed>  $canonical
     *
     * @throws AuthorizationException when the sender may not PM (e.g. TL0 NEVER).
     * @throws PmException for recoverable failures (cap exceeded, none reachable, rate-limited).
     * @throws ContentRejectedException when the body is rejected by the moderator.
     */
    public function startConversation(User $sender, array $recipientIds, ?string $subject, string $format, array $canonical): Conversation
    {
        $this->assertCanSend($sender);

        $recipients = $this->normaliseRecipients($sender, $recipientIds);

        $max = (int) config('novfora.pm.max_recipients', 10);
        if (count($recipients) > $max) {
            throw PmException::tooManyRecipients($max);
        }

        // IGNORE check (1 of 2): drop recipients who ignore the sender. Silent — never reveal the block.
        $recipients = $this->withoutIgnorers($sender, $recipients);
        if ($recipients === []) {
            throw PmException::noValidRecipients();
        }

        if (! $this->rateLimiter->attempt($sender)) {
            throw PmException::rateLimited();
        }

        ['conversation' => $conversation, 'message' => $message] = DB::transaction(function () use ($sender, $recipients, $subject, $format, $canonical) {
            $conversation = Conversation::create([
                'subject' => $this->cleanSubject($subject),
                'created_by' => $sender->id,
                'last_message_at' => now(),
            ]);

            // The starter may invite; recipients may not by default.
            $conversation->participantRows()->create(['user_id' => $sender->id, 'can_invite' => true]);
            foreach ($recipients as $rid) {
                $conversation->participantRows()->create(['user_id' => $rid, 'can_invite' => false]);
            }

            $message = $this->writeMessage($sender, $conversation, $format, $canonical);
            $conversation->forceFill(['last_message_at' => now()])->save();
            $this->markRead($sender, $conversation); // the sender has read their own opening message

            Audit::log('pm.started', $conversation, ['recipients' => count($recipients)]);

            return ['conversation' => $conversation, 'message' => $message];
        });

        MessageSent::dispatch($sender, $conversation, $message);

        return $conversation;
    }

    /**
     * Post a reply into an existing conversation. pm.send is re-checked (demotion-safe) and participation is
     * re-asserted at the service layer.
     *
     * @param  array<string,mixed>  $canonical
     *
     * @throws AuthorizationException|PmException|ContentRejectedException
     */
    public function reply(User $sender, Conversation $conversation, string $format, array $canonical): Message
    {
        $this->assertCanSend($sender);
        $this->assertActiveParticipant($sender, $conversation);

        if (! $this->rateLimiter->attempt($sender)) {
            throw PmException::rateLimited();
        }

        $message = DB::transaction(function () use ($sender, $conversation, $format, $canonical) {
            $message = $this->writeMessage($sender, $conversation, $format, $canonical);
            $conversation->forceFill(['last_message_at' => now()])->save();
            $this->markRead($sender, $conversation);

            return $message;
        });

        MessageSent::dispatch($sender, $conversation, $message);

        return $message;
    }

    /**
     * Add a participant. The inviter must be an active participant holding can_invite; a user who ignores the
     * inviter cannot be added; the mass-PM cap bounds the participant count. Idempotent — re-adds a soft-left
     * participant by clearing left_at.
     *
     * @throws AuthorizationException|PmException
     */
    public function invite(User $inviter, Conversation $conversation, int $userId): ConversationParticipant
    {
        $inviterRow = $conversation->participantRows()
            ->where('user_id', $inviter->getKey())->whereNull('left_at')->first();
        if ($inviterRow === null || ! $inviterRow->can_invite) {
            throw new AuthorizationException('You may not add participants to this conversation.');
        }

        $target = User::find($userId);
        if (! $target instanceof User) {
            throw PmException::noValidRecipients();
        }

        // IGNORE check (2 of 2): a user who ignores the inviter cannot be added to their conversation.
        if ($this->ignores($target, $inviter)) {
            throw PmException::cannotAdd();
        }

        $max = (int) config('novfora.pm.max_recipients', 10);

        return DB::transaction(function () use ($conversation, $userId, $max) {
            // Serialize concurrent invites on this conversation so the cap check + insert are ATOMIC — a plain
            // read-then-write lets two parallel invites both observe a sub-cap count and both insert, growing
            // the thread past max_recipients (TOCTOU). Lock the conversation row first, the same lockForUpdate
            // discipline ReactionService / PollService use around counter writes.
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            $row = $conversation->participantRows()->where('user_id', $userId)->first();
            $alreadyActive = $row instanceof ConversationParticipant && $row->left_at === null;

            // The cap (sender + max recipients = max + 1 active rows) is only at risk when we ADD or re-activate.
            if (! $alreadyActive && $conversation->participantRows()->whereNull('left_at')->count() >= $max + 1) {
                throw PmException::tooManyRecipients($max);
            }

            if ($row instanceof ConversationParticipant) {
                $row->forceFill(['left_at' => null])->save(); // re-activate a previously-left participant

                return $row;
            }

            $row = $conversation->participantRows()->create(['user_id' => $userId, 'can_invite' => false]);
            Audit::log('pm.invited', $conversation, ['user_id' => $userId]);

            return $row;
        });
    }

    /**
     * Report a message to the staff dashboard (reuses the existing Report polymorph). Only a participant of
     * the message's conversation may report it — no data leak to non-participants.
     *
     * @throws AuthorizationException
     */
    public function report(User $reporter, Message $message, ?string $reason = null): Report
    {
        $conversation = $message->conversation()->firstOrFail();
        $this->assertActiveParticipant($reporter, $conversation);

        $report = Report::create([
            'reporter_id' => $reporter->getKey(),
            'reportable_type' => Message::class,
            'reportable_id' => $message->getKey(),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'status' => 'open',
        ]);
        Audit::log('report.created', $message, ['reason' => $reason]);

        return $report;
    }

    /** Mark the conversation read up to now for an active participant (drives the unread badge). */
    public function markRead(User $user, Conversation $conversation): void
    {
        $conversation->participantRows()
            ->where('user_id', $user->getKey())->whereNull('left_at')
            ->update(['last_read_at' => now()]);
    }

    /** Soft-leave: the user stops reading/replying; their authored messages and the thread remain. */
    public function leave(User $user, Conversation $conversation): void
    {
        $conversation->participantRows()
            ->where('user_id', $user->getKey())->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    /**
     * Render → moderate → store one message through the SAME pipeline as a post. The sanitized HTML is the
     * security boundary (ContentRenderer wraps ContentSanitizer); a REJECT aborts the write. PMs deliberately
     * skip the post DISPLAY enhancements (word-filter replacement, oEmbed injection) — the canonical +
     * sanitized HTML is what is stored and shown, keeping the PM surface minimal.
     *
     * @param  array<string,mixed>  $canonical
     */
    private function writeMessage(User $author, Conversation $conversation, string $format, array $canonical): Message
    {
        $rendered = $this->renderer->render($format, $canonical, $this->restrictionsFor($author));

        $verdict = $this->moderator->review($author, $rendered['text']);
        if ($verdict->rejected()) {
            throw new ContentRejectedException($verdict->reasons);
        }

        return $conversation->messages()->create([
            'user_id' => $author->id,
            'body_format' => $format,
            'body_canonical' => $canonical,
            'body_html_cache' => $rendered['html'],
            'body_text' => $rendered['text'],
            'approved_state' => $verdict->held() ? 'pending' : 'approved',
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Anti-spam link/image suppression for the author, resolved through the same permission engine (no second
     * system). PMs are global-scope: a sender who lacks post.links / post.images has those suppressed from the
     * rendered HTML. In practice the sender is TL1+ (TL0 cannot PM at all), so this is normally empty — but it
     * is applied for parity and to stay correct if an operator tightens a sender's per-user gates.
     *
     * @return list<string>
     */
    private function restrictionsFor(User $author): array
    {
        $scope = Scope::global();
        $restrict = [];
        if (! $author->canDo('post.links', $scope)) {
            $restrict[] = 'links';
        }
        if (! $author->canDo('post.images', $scope)) {
            $restrict[] = 'images';
        }

        return $restrict;
    }

    private function assertCanSend(User $sender): void
    {
        if (! $sender->canDo('pm.send', Scope::global())) {
            throw new AuthorizationException('You are not allowed to send private messages.');
        }
    }

    private function assertActiveParticipant(User $user, Conversation $conversation): void
    {
        $active = $conversation->participantRows()
            ->where('user_id', $user->getKey())->whereNull('left_at')->exists();
        if (! $active) {
            throw new AuthorizationException('You are not a participant in this conversation.');
        }
    }

    /**
     * @param  array<int,int>  $ids
     * @return array<int,int> distinct, existing recipient ids excluding the sender
     */
    private function normaliseRecipients(User $sender, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $sender->getKey()));
        if ($ids === []) {
            throw PmException::noValidRecipients();
        }

        return User::whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<int,int>  $recipients
     * @return array<int,int>
     */
    private function withoutIgnorers(User $sender, array $recipients): array
    {
        if ($recipients === []) {
            return [];
        }

        $ignorers = UserRelationship::query()
            ->where('related_user_id', $sender->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->whereIn('user_id', $recipients)
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_diff($recipients, $ignorers));
    }

    /** Does $actor ignore $target? (directed edge: actor → target, type=ignore) */
    private function ignores(User $actor, User $target): bool
    {
        return UserRelationship::query()
            ->where('user_id', $actor->getKey())
            ->where('related_user_id', $target->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->exists();
    }

    private function cleanSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }
        $subject = trim($subject);

        return $subject === '' ? null : mb_substr($subject, 0, 150);
    }
}
