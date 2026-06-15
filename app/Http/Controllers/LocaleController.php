<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switcher endpoint (Wave 8.1).
 *
 * Accepts a locale ONLY from the configured allowlist (Rule::in) — an out-of-list value fails validation
 * and never touches the session. The choice is stored in the session for everyone and additionally
 * persisted to the member's profile when signed in, so it survives across devices. SetLocale reads it back
 * on the next request.
 */
final class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::codes())],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        $user = $request->user();
        if ($user !== null) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return back();
    }
}
