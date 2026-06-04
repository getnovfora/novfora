<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Forum\PostService;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-render every post by one user so its link/image suppression matches the author's CURRENT trust level
 * (phase-1.5 F-E). Dispatched on a trust change: re-suppresses links/images on demotion, reveals them on
 * promotion. Queued + chunked so a spammer with many posts never stalls the trust recompute; on the
 * baseline tier the cron line drains it.
 */
final class RegenerateUserPostHtml implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId) {}

    public function handle(PostService $posts): void
    {
        Post::where('user_id', $this->userId)->chunkById(100, function ($chunk) use ($posts) {
            foreach ($chunk as $post) {
                $posts->rerender($post);
            }
        });
    }
}
