<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Moderation actions — every one gated through the permission engine (topic.moderate at the forum scope,
 * or the PostPolicy for own/any deletes) and written to the audit log (security §3).
 */
class ModerationController extends Controller
{
    public function lock(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $topic->update(['status' => $topic->status === 'locked' ? 'open' : 'locked']);
        Audit::log($topic->status === 'locked' ? 'topic.locked' : 'topic.unlocked', $topic);

        return back();
    }

    public function pin(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $topic->update(['is_pinned' => ! $topic->is_pinned]);
        Audit::log($topic->is_pinned ? 'topic.pinned' : 'topic.unpinned', $topic);

        return back();
    }

    public function stick(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $topic->update(['type' => $topic->type === 'normal' ? 'sticky' : 'normal']);
        Audit::log('topic.type.'.$topic->type, $topic);

        return back();
    }

    public function move(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $data = $request->validate(['forum_id' => ['required', 'integer', 'exists:forums,id']]);
        $target = Forum::findOrFail($data['forum_id']);
        $this->authorizeModerate($request, $topic, $target); // must moderate the destination too

        $from = $topic->forum_id;
        $topic->update(['forum_id' => $target->id]);
        Audit::log('topic.moved', $topic, ['from' => $from, 'to' => $target->id]);

        return redirect()->route('topics.show', $topic);
    }

    public function destroyTopic(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $forumId = $topic->forum_id;
        $topic->delete(); // soft delete → recycle bin
        Audit::log('topic.deleted', $topic);

        return redirect()->route('forums.show', $forumId);
    }

    public function destroyPost(Request $request, Post $post): RedirectResponse
    {
        abort_unless($request->user()?->can('delete', $post), 403); // PostPolicy: own/any
        $topicId = $post->topic_id;
        $post->delete();
        Audit::log('post.deleted', $post);

        return redirect()->route('topics.show', $topicId);
    }

    public function restoreTopic(Request $request, int $topic): RedirectResponse
    {
        $model = Topic::withTrashed()->findOrFail($topic);
        $this->authorizeModerate($request, $model);
        $model->restore();
        Audit::log('topic.restored', $model);

        return redirect()->route('topics.show', $model);
    }

    public function restorePost(Request $request, int $post): RedirectResponse
    {
        $model = Post::withTrashed()->findOrFail($post);
        abort_unless($request->user()?->can('restore', $model), 403);
        $model->restore();
        Audit::log('post.restored', $model);

        return redirect()->route('topics.show', $model->topic_id);
    }

    /** Recycle bin — soft-deleted topics/posts in forums the actor can moderate. */
    public function recycleBin(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $topics = Topic::onlyTrashed()->with('forum')->latest('deleted_at')->get()
            ->filter(fn (Topic $t) => $t->forum && $user->canDo('topic.moderate', $t->permissionScope()))
            ->values();

        $posts = Post::onlyTrashed()->with('topic.forum')->latest('deleted_at')->get()
            ->filter(fn (Post $p) => $user->canDo('post.delete.any', Scope::thread((int) $p->topic_id)))
            ->values();

        return view('forum.recycle-bin', compact('topics', 'posts'));
    }

    /** MCP landing (security §3) — the moderator control-panel baseline: queue, reports, recycle bin. */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('bans.manage', Scope::global()), 403);

        $counts = [
            'pending_topics' => Topic::where('approved_state', 'pending')->count(),
            'pending_posts' => Post::where('approved_state', 'pending')->count(),
            'open_reports' => Report::where('status', 'open')->count(),
        ];

        return view('moderation.dashboard', compact('counts'));
    }

    /** Moderation queue (MCP, security §3) — content held by the anti-spam layer, in scopes the actor can moderate. */
    public function queue(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $topics = Topic::where('approved_state', 'pending')->with(['forum', 'author'])->latest()->get()
            ->filter(fn (Topic $t) => $t->forum && $user->canDo('topic.moderate', $t->permissionScope()))
            ->values();

        $posts = Post::where('approved_state', 'pending')->with(['topic.forum', 'author'])->latest()->get()
            ->filter(fn (Post $p) => $p->topic && $user->canDo('topic.moderate', Scope::thread((int) $p->topic_id)))
            ->values();

        return view('moderation.queue', compact('topics', 'posts'));
    }

    public function approveTopic(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $topic->update(['approved_state' => 'approved']);
        // Approve the opening post alongside the topic it belongs to.
        $topic->posts()->where('approved_state', 'pending')->orderBy('position')->first()?->update(['approved_state' => 'approved']);
        Audit::log('topic.approved', $topic);

        return back();
    }

    public function rejectTopic(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeModerate($request, $topic);
        $topic->update(['approved_state' => 'rejected']);
        $topic->delete(); // soft-delete → recoverable from the recycle bin
        Audit::log('topic.rejected', $topic);

        return redirect()->route('moderation.queue');
    }

    public function approvePost(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeModeratePost($request, $post);
        $post->update(['approved_state' => 'approved']);
        Audit::log('post.approved', $post);

        return back();
    }

    public function rejectPost(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeModeratePost($request, $post);
        $post->update(['approved_state' => 'rejected']);
        $post->delete();
        Audit::log('post.rejected', $post);

        return redirect()->route('moderation.queue');
    }

    private function authorizeModeratePost(Request $request, Post $post): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('topic.moderate', Scope::thread((int) $post->topic_id)), 403);
    }

    private function authorizeModerate(Request $request, Topic $topic, ?Forum $forum = null): void
    {
        $user = $request->user();
        $scope = $forum ? $forum->permissionScope() : $topic->permissionScope();
        abort_unless($user instanceof User && $user->canDo('topic.moderate', $scope), 403);
    }
}
