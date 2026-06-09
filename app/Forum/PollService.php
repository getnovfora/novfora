<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\AntiSpam\ContentModerator;
use App\AntiSpam\ContentRejectedException;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single write + read path for topic polls (P2-M1). Vote integrity is the crux (amendment #5): the only
 * DB constraint is UNIQUE(poll_option_id, user_id); single-choice "one option per user" and multi-choice
 * `max_choices` are enforced HERE by locking the poll row for the duration of the vote transaction, so
 * concurrent votes by the same user serialise and cannot race past the cap. Option tallies are recomputed
 * authoritatively from `poll_votes` (drift-free). The read side honours RH-9: a primitives-only display cache
 * per (poll, version), version-bumped on every vote/close.
 */
final class PollService
{
    private const DISPLAY_TTL_MINUTES = 30;

    private const MAX_OPTIONS = 20;

    public function __construct(private readonly ContentModerator $moderator) {}

    /**
     * Create a topic's poll (one per topic). $options are plain-text labels; question + labels are reduced to
     * plain text and screened by the moderator (the same spam gate as posts). Wires the topics.poll_id seam.
     *
     * @param  list<string>  $options
     *
     * @throws \InvalidArgumentException|ContentRejectedException
     */
    public function createPoll(
        User $author,
        Topic $topic,
        string $question,
        array $options,
        bool $isMultiple = false,
        ?int $maxChoices = null,
        ?\DateTimeInterface $closesAt = null,
    ): Poll {
        $question = $this->cleanText($question);
        $labels = [];
        foreach ($options as $option) {
            $label = $this->cleanText((string) $option);
            if ($label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        $labels = array_slice($labels, 0, self::MAX_OPTIONS);

        if ($question === '') {
            throw new \InvalidArgumentException('A poll needs a question.');
        }
        if (count($labels) < 2) {
            throw new \InvalidArgumentException('A poll needs at least two distinct options.');
        }

        // Screen the free text through the same moderator as post content (spam / word filters).
        $verdict = $this->moderator->review($author, $question.' '.implode(' ', $labels));
        if ($verdict->rejected()) {
            throw new ContentRejectedException($verdict->reasons);
        }

        $maxChoices = $isMultiple ? max(1, min($maxChoices ?? count($labels), count($labels))) : null;

        return DB::transaction(function () use ($topic, $question, $labels, $isMultiple, $maxChoices, $closesAt): Poll {
            $poll = Poll::create([
                'topic_id' => $topic->getKey(),
                'question' => $question,
                'is_multiple' => $isMultiple,
                'max_choices' => $maxChoices,
                'closes_at' => $closesAt,
                'is_closed' => false,
                'tenant_id' => $topic->tenant_id,
            ]);

            foreach ($labels as $i => $label) {
                PollOption::create([
                    'poll_id' => $poll->getKey(),
                    'label' => $label,
                    'position' => $i,
                    'vote_count' => 0,
                    'tenant_id' => $topic->tenant_id,
                ]);
            }

            // The thread page eager-loads the poll through this seam.
            $topic->forceFill(['poll_id' => $poll->getKey()])->saveQuietly();

            Audit::log('poll.created', $poll, ['topic_id' => $topic->getKey(), 'options' => count($labels)]);

            return $poll->load('options');
        });
    }

    /**
     * Cast (or replace) a user's vote. The submitted $optionIds become the user's COMPLETE vote set:
     *   - closed poll   → rejected
     *   - single-choice → exactly one option (replaces any prior vote)
     *   - multi-choice  → 1..max_choices distinct options of THIS poll (replaces the prior set)
     *
     * @param  list<int>  $optionIds
     *
     * @throws PollVoteException
     */
    public function vote(User $user, Poll $poll, array $optionIds): void
    {
        DB::transaction(function () use ($user, $poll, $optionIds): void {
            // Integrity anchor: lock the poll row so concurrent votes by the same user serialise. Without a
            // DB UNIQUE(poll_id,user_id) (which would forbid multi-choice), this is what makes single-choice
            // and the max_choices cap unraceable. Re-read fresh under the lock.
            $poll = Poll::whereKey($poll->getKey())->lockForUpdate()->firstOrFail();

            if ($poll->isClosed()) {
                throw new PollVoteException('This poll is closed.');
            }

            $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
            if ($optionIds === []) {
                throw new PollVoteException('Select at least one option.');
            }

            // Every submitted option must belong to THIS poll (no cross-poll vote injection).
            $validIds = PollOption::where('poll_id', $poll->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (array_diff($optionIds, $validIds) !== []) {
                throw new PollVoteException('Invalid option for this poll.');
            }

            if (! $poll->is_multiple && count($optionIds) !== 1) {
                throw new PollVoteException('This poll allows only a single choice.');
            }
            $cap = $poll->is_multiple ? (int) ($poll->max_choices ?: count($validIds)) : 1;
            if (count($optionIds) > $cap) {
                throw new PollVoteException("You may choose at most {$cap} option(s).");
            }

            // The submitted set REPLACES any prior vote, atomically inside the locked transaction.
            $prior = PollVote::where('poll_id', $poll->getKey())
                ->where('user_id', $user->getKey())
                ->pluck('poll_option_id')->map(fn ($i) => (int) $i)->all();

            PollVote::where('poll_id', $poll->getKey())->where('user_id', $user->getKey())->delete();

            foreach ($optionIds as $optionId) {
                PollVote::create([
                    'poll_id' => $poll->getKey(),
                    'poll_option_id' => $optionId,
                    'user_id' => $user->getKey(),
                    'tenant_id' => $poll->tenant_id,
                ]);
            }

            foreach (array_unique([...$prior, ...$optionIds]) as $optionId) {
                $this->recountOption((int) $optionId);
            }
            $this->bumpVersion((int) $poll->getKey());

            Audit::log('poll.voted', $poll, ['user_id' => $user->getKey(), 'options' => $optionIds]);
        });
    }

    /** Close a poll early (staff/author action). */
    public function close(User $actor, Poll $poll): void
    {
        $poll->forceFill(['is_closed' => true])->saveQuietly();
        $this->bumpVersion((int) $poll->getKey());
        Audit::log('poll.closed', $poll, ['user_id' => $actor->getKey()]);
    }

    /** Authoritatively recompute one option's tally from the source table. */
    private function recountOption(int $optionId): void
    {
        $count = PollVote::where('poll_option_id', $optionId)->count();
        PollOption::whereKey($optionId)->update(['vote_count' => $count]);
    }

    // ── read side (RH-9: primitives only, rehydrate after the boundary) ────────────────────────────

    /**
     * Primitives-only display data for a poll, cached per (poll, version). The effective closed state and the
     * close timestamp are returned RAW (is_closed flag + closes_at iso) so the time-based close is evaluated
     * AFTER the cache boundary — never frozen into the cache.
     *
     * @return array{id:int, question:string, is_multiple:bool, max_choices:?int, is_closed:bool, closes_at:?string, total_voters:int, options:list<array{id:int,label:string,count:int}>}
     */
    public function displayData(Poll $poll): array
    {
        $version = $this->version((int) $poll->getKey());

        return Cache::remember(
            "hearth.poll.display.p{$poll->getKey()}.v{$version}",
            now()->addMinutes(self::DISPLAY_TTL_MINUTES),
            function () use ($poll): array {
                $options = PollOption::where('poll_id', $poll->getKey())
                    ->orderBy('position')->orderBy('id')
                    ->get(['id', 'label', 'vote_count']);

                return [
                    'id' => (int) $poll->getKey(),
                    'question' => (string) $poll->question,
                    'is_multiple' => (bool) $poll->is_multiple,
                    'max_choices' => $poll->max_choices !== null ? (int) $poll->max_choices : null,
                    'is_closed' => (bool) $poll->is_closed,                  // RAW flag
                    'closes_at' => $poll->closes_at?->toIso8601String(),     // evaluate "past" after the boundary
                    'total_voters' => (int) PollVote::where('poll_id', $poll->getKey())->distinct('user_id')->count('user_id'),
                    'options' => $options->map(fn ($o): array => [
                        'id' => (int) $o->id,
                        'label' => (string) $o->label,
                        'count' => (int) $o->vote_count,
                    ])->all(),
                ];
            },
        );
    }

    /**
     * The option ids this user has voted for on the poll (to render their selection). Per-viewer, not cached.
     *
     * @return list<int>
     */
    public function votedOptionIds(User $user, Poll $poll): array
    {
        if (! $user->exists) {
            return [];
        }

        return PollVote::where('poll_id', $poll->getKey())
            ->where('user_id', $user->getKey())
            ->pluck('poll_option_id')->map(fn ($i) => (int) $i)->all();
    }

    private function cleanText(string $text): string
    {
        // Plain-text labels: strip ALL markup (labels are not rich text) + trim + bound. Blade {{ }} escapes
        // on render, so the stored value is inert.
        return trim(mb_substr(strip_tags($text), 0, 255));
    }

    // ── per-poll cache version (TTL ≫ display TTL so it never resets before the display entries expire) ──

    private function version(int $pollId): int
    {
        return (int) Cache::get("hearth.poll.ver.p{$pollId}", 0);
    }

    private function bumpVersion(int $pollId): void
    {
        $key = "hearth.poll.ver.p{$pollId}";
        if (! Cache::add($key, 1, now()->addYear())) {
            Cache::increment($key);
        }
    }
}
