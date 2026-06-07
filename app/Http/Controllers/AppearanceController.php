<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
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
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'color_mode' => ['sometimes', 'required', Rule::in(self::COLOR_MODES)],
            'density' => ['sometimes', 'required', Rule::in(self::DENSITIES)],
        ]);

        if (array_key_exists('color_mode', $data)) {
            $user->color_mode = $data['color_mode'];
        }
        if (array_key_exists('density', $data)) {
            $user->density = $data['density'];
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
