<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clubs\ClubCreation;
use App\Clubs\ClubMembershipException;
use App\Clubs\ClubMembershipService;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clubs (Phase 4 · M1.1): the public directory, a club home page, and the create/edit form shells (the forms
 * themselves are Livewire SFCs that re-assert authorization). Listing/content visibility is enforced through
 * the Club model's single-source-of-truth gates (ADR-0047) — an unlisted club a viewer may not see returns
 * 404 (no disclosure), exactly like the members directory.
 */
class ClubController extends Controller
{
    /** The club directory — clubs whose existence the viewer may see, newest-active first. */
    public function index(Request $request): View
    {
        $viewer = $request->user();

        $clubs = Club::query()
            ->listableTo($viewer)
            ->orderByDesc('member_count')
            ->orderBy('name')
            ->paginate(24);

        return view('clubs.index', [
            'clubs' => $clubs,
            'canCreate' => app(ClubCreation::class)->canCreate($viewer),
        ]);
    }

    /** A club's home page. 404 (no disclosure) if the viewer may not even see the club exists. */
    public function show(Request $request, Club $club): View
    {
        $viewer = $request->user();
        abort_unless($club->isListingVisibleTo($viewer), 404);

        // Roster preview: owners + moderators are always shown on a visible club; the full member roster is
        // gated to content-visible viewers (M1.3 builds the full roster page).
        $staff = $club->memberships()
            ->with('user')
            ->where('status', 'active')
            ->whereIn('role', ['owner', 'moderator'])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->get();

        return view('clubs.show', [
            'club' => $club,
            'staff' => $staff,
            'viewerRole' => $club->roleOf($viewer),
            'contentVisible' => $club->isContentVisibleTo($viewer),
        ]);
    }

    /** The create-club form shell. Gated on the creation policy (ClubCreation / M1.6). */
    public function create(Request $request): View
    {
        abort_unless(app(ClubCreation::class)->canCreate($request->user()), 403);

        return view('clubs.create');
    }

    /** The edit-club form shell. Owner (or global staff) only. */
    public function edit(Request $request, Club $club): View
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $club->isManageableBy($viewer), 403);

        return view('clubs.edit', ['club' => $club]);
    }

    /** The roster page (M1.3). Gated to content-visible viewers; the SFC exposes management to owners only. */
    public function members(Request $request, Club $club): View
    {
        abort_unless($club->isContentVisibleTo($request->user()), 404);

        return view('clubs.members', ['club' => $club]);
    }

    /** Invitation confirm page (M1.3). The token is the secret; this only renders a confirm form (POST accepts). */
    public function invite(Request $request, Club $club, ClubInvitation $invitation): View
    {
        abort_unless((int) $invitation->club_id === (int) $club->id, 404);

        return view('clubs.invite', [
            'club' => $club,
            'invitation' => $invitation,
            'valid' => $invitation->isPending(),
        ]);
    }

    /** Accept an invitation (M1.3). Validates ownership, single-use, expiry, and email binding in the service. */
    public function acceptInvite(Request $request, Club $club, ClubInvitation $invitation): RedirectResponse
    {
        abort_unless((int) $invitation->club_id === (int) $club->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            app(ClubMembershipService::class)->acceptInvite($invitation, $user);
        } catch (ClubMembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('clubs.show', $club)->with('status', __('Welcome to :club!', ['club' => $club->name]));
    }
}
