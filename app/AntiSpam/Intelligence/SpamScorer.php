<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Intelligence;

use App\Models\Post;
use App\Models\User;

/**
 * Advanced spam intelligence (Phase 4 · M6.1). Scores a post against several reputation/behavioural signals
 * and decides whether to HOLD it for the moderation queue. It NEVER rejects or deletes — the strongest action
 * it can take is HOLD (ADR-0007 §2.4 posture, enforced by ContentModerator capping it at HOLD).
 *
 * FALSE-POSITIVE GUARD (first): trusted members are EXEMPT — staff, trust level ≥ trusted_floor, or
 * ≥ established_posts approved posts are never scored. Short content (< 12 fingerprint chars) never triggers
 * the similarity signal, so common short replies ("thanks", "+1") are not flagged as duplicates. All
 * thresholds/weights are config-tunable (config/novfora.php → antispam.intelligence).
 *
 * Signals: content SIMILARITY (the author reposted near-identical content recently — the classic spam tell),
 * BURST (posting faster than the window allows, beyond the per-minute rate limiter), NEW ACCOUNT, and TL0.
 */
final class SpamScorer
{
    public function score(User $author, string $text): SpamScore
    {
        if (! (bool) config('novfora.antispam.intelligence.enabled', true)) {
            return SpamScore::clear();
        }

        // FP guard — trusted members are never held by intelligence.
        if ($this->isTrusted($author)) {
            return SpamScore::clear();
        }

        /** @var array<string,int> $weights */
        $weights = (array) config('novfora.antispam.intelligence.weights', []);
        $signals = [];

        if ($this->isDuplicateOfRecent($author, $text)) {
            $signals['similarity'] = (int) ($weights['similarity'] ?? 3);
        }
        if ($this->isBursting($author)) {
            $signals['burst'] = (int) ($weights['burst'] ?? 2);
        }
        if ($this->isNewAccount($author)) {
            $signals['new_account'] = (int) ($weights['new_account'] ?? 1);
        }
        if ($author->trustLevel() === 0) {
            $signals['tl0'] = (int) ($weights['tl0'] ?? 1);
        }

        $score = array_sum($signals);
        $threshold = max(1, (int) config('novfora.antispam.intelligence.hold_threshold', 3));

        return new SpamScore($score, $signals, $score >= $threshold, array_keys($signals));
    }

    private function isTrusted(User $author): bool
    {
        if ($author->isStaff()) {
            return true;
        }
        if ($author->trustLevel() >= (int) config('novfora.antispam.intelligence.trusted_floor', 3)) {
            return true;
        }

        $established = (int) config('novfora.antispam.intelligence.established_posts', 50);

        return (int) ($author->post_count ?? 0) >= $established;
    }

    private function isNewAccount(User $author): bool
    {
        $hours = (int) config('novfora.antispam.intelligence.new_account_hours', 48);
        $created = $author->created_at;

        return $created !== null && $created->gt(now()->subHours($hours));
    }

    private function isBursting(User $author): bool
    {
        $window = max(1, (int) config('novfora.antispam.intelligence.burst_window_minutes', 10));
        $threshold = max(2, (int) config('novfora.antispam.intelligence.burst_threshold', 5));

        $count = Post::query()
            ->where('user_id', $author->getKey())
            ->where('created_at', '>=', now()->subMinutes($window))
            ->count();

        return $count >= $threshold;
    }

    private function isDuplicateOfRecent(User $author, string $text): bool
    {
        $fingerprint = $this->fingerprint($text);
        if (mb_strlen($fingerprint) < 12) {
            return false; // too short to fingerprint reliably (avoid flagging "thanks", "+1", …)
        }

        $window = max(1, (int) config('novfora.antispam.intelligence.similarity_window_hours', 24));
        $limit = max(1, (int) config('novfora.antispam.intelligence.recent_posts_limit', 25));

        $recent = Post::query()
            ->where('user_id', $author->getKey())
            ->where('created_at', '>=', now()->subHours($window))
            ->latest('id')
            ->limit($limit)
            ->pluck('body_text');

        foreach ($recent as $body) {
            if ($this->fingerprint((string) $body) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /** Normalise text to a comparison fingerprint: lowercase + collapsed whitespace. */
    private function fingerprint(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }
}
