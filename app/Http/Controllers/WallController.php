<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\WallService;
use App\Models\ProfilePost;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Profile-wall actions. Posting is the ⚡wall-composer SFC (rate-limited + re-gated there); this controller
 * owns the DELETE path — an author removing their own status, the wall owner curating their space, or a global
 * moderator taking one down. Every branch is authorised through WallService::canDelete (the single source of
 * truth the profile view also consults, so the control and the endpoint can never disagree).
 */
final class WallController extends Controller
{
    public function destroy(Request $request, ProfilePost $profilePost, WallService $wall): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $wall->canDelete($actor, $profilePost), 403);

        $profilePost->delete(); // soft delete — recoverable
        Audit::log('wall.deleted', $profilePost);

        return back();
    }
}
