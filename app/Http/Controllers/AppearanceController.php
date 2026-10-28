<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SiteTheme;
use App\Models\User;
use App\Theme\StyleThemeManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Appearance settings (default-theme phase, PART 2): per-user colour mode + density. These are the only
 * behaviour additions of the theme pass. The full settings form posts here (and works with NO JavaScript);
 * the header quick-toggle posts a single field via fetch (expectsJson). Persisted by direct assignment
 * (user-owned, non-privilege fields), never mass-assignment.
 */
class AppearanceController extends Controller
{
    /** Accepted values, also reused by the view to render the option lists. */
    public const COLOR_MODES = ['auto', 'light', 'dark'];

    public const DENSITIES = ['comfortable', 'compact'];

    public function edit(Request $request): View
    {
        return view('settings.appearance', [
            'user' => $this->user($request),
            'colorModes' => self::COLOR_MODES,
            'densities' => self::DENSITIES,
            // U10: the styles members may choose from (empty → the Style card doesn't render).
            'styleOptions' => app(StyleThemeManager::class)->selectable(),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);

        // U10: the chooser may only reference a LIVE user-selectable style ('' = back to the site default).
        $selectableIds = array_map(
            static fn (SiteTheme $t): int => (int) $t->getKey(),
            app(StyleThemeManager::class)->selectable(),
        );

        $data = $request->validate([
            'color_mode' => ['sometimes', 'required', Rule::in(self::COLOR_MODES)],
            'density' => ['sometimes', 'required', Rule::in(self::DENSITIES)],
            // Presence opt-in (Phase 4 · M4.3) — a privacy toggle; default false (security-by-default).
            'show_online_status' => ['sometimes', 'boolean'],
            'style_theme_id' => ['sometimes', 'nullable', Rule::in($selectableIds)],
        ]);

        if (array_key_exists('color_mode', $data)) {
            $user->color_mode = $data['color_mode'];
        }
        if (array_key_exists('density', $data)) {
            $user->density = $data['density'];
        }
        if (array_key_exists('show_online_status', $data)) {
            $user->show_online_status = (bool) $data['show_online_status'];
        }
        if (array_key_exists('style_theme_id', $data)) {
            $user->style_theme_id = $data['style_theme_id'] !== null && $data['style_theme_id'] !== ''
                ? (int) $data['style_theme_id']
                : null;
        }
        $user->save();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'color_mode' => $user->color_mode,
                'density' => $user->density,
            ]);
        }

        return back()->with('status', 'Appearance updated.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
