<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The per-section dashboard landing (ACP v3 · v3-h, foundations §3). One invokable controller serves every
 * rail section's landing (admin.<section>): it derives the section from the route name and renders the shared
 * `admin.section` view, which lists the section's sub-pages and is the home for section widgets as features
 * land. Read-only; authorization is the admin route group (admin.access + staff-2FA), so no in-controller guard.
 */
class SectionController extends Controller
{
    /** The rail sections that have a generic landing here (Overview = dashboard, Analytics = its own page). */
    private const SECTIONS = ['forums', 'members', 'groups', 'moderation', 'appearance', 'plugins', 'settings', 'system', 'security'];

    public function __invoke(Request $request): View
    {
        $section = (string) Str::of($request->route()?->getName() ?? '')->after('admin.');

        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return view('admin.section', ['section' => $section]);
    }
}
