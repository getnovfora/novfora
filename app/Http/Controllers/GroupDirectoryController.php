<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Groups\GroupDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The public Groups directory (ACP v3 · v3-e, ADR-0083). Lists only groups an admin has explicitly made
 * public (is_public = true); the query is delegated entirely to GroupDirectory::publicGroups() — this
 * controller is a thin shell that hands the result to the view.
 *
 * PRIVACY: only the aggregate member COUNT is ever surfaced here; the roster (who belongs to a group) is
 * never exposed. GroupDirectory::publicGroups() enforces the is_public filter — a hidden group cannot leak.
 */
final class GroupDirectoryController extends Controller
{
    /** The public group directory. */
    public function index(Request $request): View
    {
        return view('groups.index', [
            'groups' => GroupDirectory::publicGroups(),
        ]);
    }
}
