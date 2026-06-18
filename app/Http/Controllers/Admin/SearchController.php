<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminNavigation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The global ACP search (ACP v3 · v3-h, foundations §3 / spec §1): one query across admin PAGES, SETTINGS
 * fields, and MEMBERS. The section sidebar's quick-filter handles instant page/settings jumps client-side;
 * pressing Enter runs this server-side search, which adds member lookup (a fixed index can't carry the user
 * table). Read-only; the admin route group gates it (admin.access + staff-2FA). Member results link to the
 * member's profile — the dedicated member-management surface lands in a later slice.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $pages = [];
        $settings = [];
        $members = collect();

        if ($q !== '') {
            $needle = Str::lower($q);
            foreach (AdminNavigation::searchIndex() as $entry) {
                if (! str_contains(Str::lower($entry['label'].' '.$entry['group']), $needle)) {
                    continue;
                }
                $entry['type'] === 'setting' ? $settings[] = $entry : $pages[] = $entry;
            }

            $members = User::query()
                ->where(function ($w) use ($q) {
                    $w->where('username', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('display_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                })
                ->orderBy('username')
                ->limit(10)
                ->get(['id', 'username', 'name', 'display_name', 'email']);
        }

        return view('admin.search-results', compact('q', 'pages', 'settings', 'members'));
    }
}
