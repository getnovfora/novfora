<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\TopicRead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * "What's new" / unread (data-model §9). Lists approved topics with activity since the viewer last read them
 * (or that they've never opened), bounded to topics newer than the account and to forums they can see.
 */
class WhatsNewController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $reads = TopicRead::where('user_id', $user->getKey())->get()->keyBy('topic_id');
        $since = $user->created_at ?? now()->subYear();

        $topics = Topic::query()
            ->where('approved_state', 'approved')
            ->whereNotNull('last_posted_at')
            ->where('last_posted_at', '>', $since)
            ->orderByDesc('last_posted_at')
            ->limit(50)
            ->with(['forum', 'author'])
            ->get()
            ->filter(function (Topic $topic) use ($reads, $user) {
                $read = $reads->get($topic->id)?->last_read_at;
                $unread = $read === null || $topic->last_posted_at?->gt($read);

                return $unread && $user->canDo('forum.view', $topic->permissionScope());
            })
            ->values();

        return view('whats-new.index', compact('topics'));
    }
}
