<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\AnnouncementService;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Announcement dismissal (U4, NOV-102). Authenticated-only: a guest has nowhere to persist a dismissal.
 * We 404 (not 403) anything that isn't a live announcement the viewer is actually in the audience for, so
 * this endpoint can neither confirm the existence of a targeted announcement to a user outside its audience
 * nor accrue dismissal rows for topics the caller could never see. Dismissal itself is idempotent.
 */
final class AnnouncementController extends Controller
{
    public function dismiss(Request $request, Topic $topic, AnnouncementService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($topic->isAnnouncement() && $service->isVisibleTo($topic, $user), 404);

        $service->dismiss($user, $topic);

        return back();
    }
}
