<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
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

        $reports = Report::where('status', 'open')->with(['reporter', 'reportable'])->latest()->paginate(30);

        return view('moderation.reports', compact('reports'));
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
