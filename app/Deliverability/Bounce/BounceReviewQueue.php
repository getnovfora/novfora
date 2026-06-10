<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

use App\Models\BounceReview;

/**
 * P2-M2 — the single place a NON-VERP bounce candidate is staged for staff review (spike-p2-memo §2b / §8).
 * Idempotent on a content dedupe key (a re-polled identical message never duplicates a row). It only ever
 * QUEUES — it never suppresses; that decision is the staff member's in the ACP. The queued email is UNVERIFIED.
 */
final class BounceReviewQueue
{
    /**
     * Queue a parsed review candidate. Returns true only if a new row was created.
     *
     * @param  array{email:string, type:string, permanent:bool, excerpt:string}  $candidate
     */
    public function enqueue(array $candidate): bool
    {
        $email = strtolower(trim($candidate['email']));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $type = $candidate['type'] === BounceEvent::COMPLAINT ? BounceEvent::COMPLAINT : BounceEvent::BOUNCE;
        $excerpt = (string) $candidate['excerpt'];
        $dedupeKey = hash('sha256', $email.'|'.$type.'|'.$excerpt);

        $row = BounceReview::firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'candidate_email' => $email,
                'event_type' => $type,
                'permanent' => (bool) $candidate['permanent'],
                'excerpt' => $excerpt,
                'status' => BounceReview::PENDING,
                'created_at' => now(),
            ],
        );

        return $row->wasRecentlyCreated;
    }
}
