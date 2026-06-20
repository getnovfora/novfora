<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The per-section dashboard landing (ACP v3 · v3-h, foundations §3). One invokable controller serves every
 * rail section's landing (admin.<section>): it derives the section from the route name and renders the shared
 * `admin.section` view, which lists the section's sub-pages and is the home for section widgets as features
 * land. Read-only. The admin route group gates admin.access + staff-2FA; v3-a (ADR-0080) additionally gates
 * each landing on its per-section key, so a bundle-restricted admin only reaches the sections they were granted.
 */
class SectionController extends Controller
{
    /** The rail sections that have a generic landing here (Overview = dashboard, Analytics = its own page). */
    private const SECTIONS = ['forums', 'members', 'groups', 'moderation', 'appearance', 'plugins', 'settings', 'system', 'security'];

    public function __invoke(Request $request): View
    {
        $section = (string) Str::of($request->route()?->getName() ?? '')->after('admin.');

        abort_unless(in_array($section, self::SECTIONS, true), 404);

        // Per-section access (v3-a): a full admin holds every section key via the preset; a restricted admin only
        // their bundle's subset; admin.security.access is co-owner-only. The rail hides what a user can't open, but
        // a hand-typed URL must be refused here too — the landing is the authority for direct loads.
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo("admin.{$section}.access", Scope::global()), 403);

        return view('admin.section', ['section' => $section]);
    }
}
