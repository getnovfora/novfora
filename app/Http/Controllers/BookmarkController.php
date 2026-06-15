<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\BookmarkService;
use App\Models\Bookmark;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "Saved" view (member tool 2.1) — the signed-in user's bookmarked topics + posts, newest first. Each
 * item is re-checked for current visibility (forum.view), so a bookmark in a forum the user lost access to,
 * or whose target was deleted, simply drops out.
 */
class BookmarkController extends Controller
{
    public function index(Request $request, BookmarkService $bookmarks): View
    {
        /** @var User $user */
        $user = $request->user();
        $page = $bookmarks->paginate($user, 20);

        $items = collect($page->items())
            ->map(fn (Bookmark $b) => $this->present($b, $user))
            ->filter()
            ->values();

        return view('saved.index', ['page' => $page, 'items' => $items]);
    }

    /** @return array{kind:string,title:string,url:string,saved_at:mixed}|null */
    private function present(Bookmark $bookmark, User $user): ?array
    {
        /** @var Model|null $target soft-deleted targets resolve to null and drop out */
        $target = $bookmark->bookmarkable;

        if ($target instanceof Topic) {
            $topic = $target;
            $url = route('topics.show', $topic);
            $kind = 'Topic';
        } elseif ($target instanceof Post) {
            $topic = $target->topic;
            if (! $topic instanceof Topic) {
                return null;
            }
            $url = route('topics.show', $topic).'#post-'.$target->getKey();
            $kind = 'Post';
        } else {
            return null;
        }

        if (! $user->canDo('forum.view', Scope::forum((int) $topic->forum_id))) {
            return null; // lost access since saving
        }
        // M1.5: a bookmark in a club forum drops out unless the user can still see the club's content
        // (e.g. they left the club, or it went private after they saved).
        $forum = $topic->forum;
        if ($forum instanceof Forum && ! $forum->clubContentVisibleTo($user)) {
            return null;
        }

        return ['kind' => $kind, 'title' => (string) $topic->title, 'url' => $url, 'saved_at' => $bookmark->created_at];
    }
}
