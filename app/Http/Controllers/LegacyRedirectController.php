<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the importer's 301 redirect maps (ADR-0034) as the route FALLBACK — so the redirects table is only
 * consulted for an otherwise-unmatched URL (a legacy link), never on the hot path. A match 301s to the new
 * canonical path; everything else 404s as usual. Defensive against a missing table (pre-install).
 */
final class LegacyRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $from = '/'.ltrim($request->getRequestUri(), '/');
        try {
            if (Schema::hasTable('redirects')) {
                $redirect = Redirect::query()->where('from_path', $from)->first();
                if ($redirect instanceof Redirect) {
                    return redirect($redirect->to_path, $redirect->status);
                }
            }
        } catch (\Throwable) {
            // fall through to a normal 404
        }

        abort(404);
    }
}
