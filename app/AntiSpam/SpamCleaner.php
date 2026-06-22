<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Ban;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Moderation\OwnerStrandException;
use App\Moderation\OwnerStrandGuard;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Spam Cleaner (ADR-0007 §2.4, XenForo concept): one action soft-deletes all of a flagged account's content
 * AND bans the account. Soft-delete keeps everything recoverable from the recycle bin if the call was a
 * mistake. Fully audited.
 */
final class SpamCleaner
{
    public function __construct(private readonly OwnerStrandGuard $ownerGuard) {}

    /**
     * @return array{topics:int, posts:int}
     *
     * @throws OwnerStrandException when the target is the last administrator / sole co-owner
     */
    public function clean(User $actor, User $target, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $target, $reason) {
            // Owner-strand backstop (apex, ADR-0100), the FIRST act under the transaction: a clean BANS the
            // account, so refuse + roll back (deleting nothing) before stranding the sole owner.
            $this->ownerGuard->assertBanWontStrandOwnerTier($target);

            $topics = 0;
            foreach (Topic::where('user_id', $target->getKey())->get() as $topic) {
                $topic->delete(); // soft-delete → recycle bin
                $topics++;
            }

            $posts = 0;
            foreach (Post::where('user_id', $target->getKey())->get() as $post) {
                $post->delete();
                $posts++;
            }

            $target->forceFill(['status' => 'banned'])->save();
            Ban::create([
                'user_id' => $target->getKey(),
                'type' => 'user',
                'scope_type' => 'global',
                'reason' => $reason ?? 'Spam cleaner',
            ]);

            Audit::log('spam.cleaned', $target, ['topics' => $topics, 'posts' => $posts, 'by' => $actor->getKey()]);

            return ['topics' => $topics, 'posts' => $posts];
        });
    }
}
