<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Models\WarningType;
use App\Permissions\Scope;
use App\Support\ActorRank;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Report system → staff dashboard (security §3). Any member can report a post; staff (bans.manage) review
 * and resolve them. Resolution is audited.
 */
class ReportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $post = Post::findOrFail($data['post_id']);
        Report::create([
            'reporter_id' => $user->getKey(),
            'reportable_type' => Post::class,
            'reportable_id' => $post->getKey(),
            'reason' => $data['reason'] ?? null,
            'status' => 'open',
        ]);
        Audit::log('report.created', $post, ['reason' => $data['reason'] ?? null]);

        return back()->with('status', 'Thanks — a moderator will review this.');
    }

    public function index(Request $request): View
    {
        $this->authorizeStaff($request);

        /** @var User $viewer */
        $viewer = $request->user();

        // Eager-load the reportable per type (a Post report enriches the card with its author/topic/forum; a
        // Message report — reportable_type = Message — stays a bare header) so the review renders the post
        // excerpt + author + permalink with NO per-report query.
        $reports = Report::where('status', 'open')
            ->with(['reporter.groups', 'reportable' => function (Relation $morph): void {
                if ($morph instanceof MorphTo) {
                    $morph->morphWith([Post::class => ['author.groups', 'topic.forum']]);
                }
            }])
            ->latest()
            ->paginate(30);

        // Per-report review context, precomputed so the view stays declarative: the reported post (only when
        // this viewer may see its forum — no private-club leak), a permalink to it, and the moderator actions
        // THIS viewer is permitted (gated by the SAME engine/policies the action routes enforce).
        $cards = $reports->getCollection()
            ->mapWithKeys(fn (Report $report): array => [$report->id => $this->reviewCard($report, $viewer)])
            ->all();

        // Loaded ONCE for the inline controls (not per card): move destinations + active warning types.
        $moveTargets = Forum::query()->whereNull('club_id')->where('type', 'forum')
            ->orderBy('position')->orderBy('title')->get(['id', 'title']);
        $warningTypes = WarningType::query()->where('is_active', true)->orderBy('label')->get(['id', 'label']);

        return view('moderation.reports', compact('reports', 'cards', 'moveTargets', 'warningTypes'));
    }

    /**
     * Build the review context for one open report. Everything content-bearing (the post body, the permalink,
     * the topic context, the moderator actions) is gated behind the viewer's forum.view + club visibility, so
     * a staff member with bans.manage but no access to a closed-club forum sees only the bare report header.
     *
     * @return array<string, mixed>
     */
    private function reviewCard(Report $report, User $viewer): array
    {
        $post = $report->reportable instanceof Post ? $report->reportable : null;
        $topic = $post?->topic;
        $forum = $topic?->forum;

        $canSee = $post instanceof Post
            && $topic instanceof Topic
            && $forum instanceof Forum
            && $forum->clubContentVisibleTo($viewer)
            && $viewer->canDo('forum.view', $forum->permissionScope());

        if (! $canSee) {
            return ['canSee' => false];
        }

        $author = $post->author;

        return [
            'canSee' => true,
            'post' => $post,
            'topic' => $topic,
            'author' => $author,
            'permalink' => route('topics.show', $topic).'#post-'.$post->getKey(),
            'topicLocked' => $topic->status === 'locked',
            'topicPinned' => (bool) $topic->is_pinned,
            // Lock/Pin/Move/Delete-topic share the topic.moderate gate (move re-checks the destination server-side).
            'canModerateTopic' => $viewer->canDo('topic.moderate', $topic->permissionScope()),
            // Edit/Delete post go through PostPolicy (own/any), the same gate the topic view + routes use.
            'canEditPost' => $viewer->can('update', $post),
            'canDeletePost' => $viewer->can('delete', $post),
            // Warn the author: bans.manage + the rank guard (a mod can't warn an equal/higher-ranked author).
            'canWarn' => $author instanceof User
                && ActorRank::canActOn($viewer, $author)
                && $viewer->canDo('bans.manage', Scope::global()),
        ];
    }

    public function resolve(Request $request, Report $report): RedirectResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'action' => ['nullable', 'in:resolved,dismissed'],
            'resolution' => ['nullable', 'string', 'max:500'],
        ]);

        $report->update([
            'status' => $data['action'] ?? 'resolved',
            'handled_by' => $request->user()?->getKey(),
            'resolution' => $data['resolution'] ?? null,
            'handled_at' => now(),
        ]);
        Audit::log('report.'.$report->status, $report);

        return back();
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('bans.manage', Scope::global()), 403);
    }
}
