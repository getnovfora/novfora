<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The route-level FRIENDLY-403 (NOV-96 / ADR-0109) — the fifth outcome of the permission-aware UI contract and
 * the page-level sibling of the <x-action> disabled-with-reason state. A full-page denial renders a contextual,
 * auth-aware explainer (with a sign-in call-to-action for guests) instead of the bare 403 error page — the
 * BETA-3 "DM 403 → friendly explainer" pattern (ADR-0102) generalised.
 *
 * It carries ONLY a curated, translated reason (passed through {@see deny()}), never a raw exception/technical
 * message, so nothing internal leaks. It is still a real 403 (extends AccessDeniedHttpException), so middleware,
 * status codes, and JSON clients behave normally; only the HTML rendering is friendlier.
 */
final class FriendlyDenialException extends AccessDeniedHttpException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, $previous);
    }

    /**
     * Deny the current request with a friendly, translated reason. Prefer this over `abort(403)` wherever a
     * signed-in-or-guest human hit a page they cannot access and an explainer (+ a way forward) helps.
     *
     * @param  string  $reasonKey  an i18n key resolving to a USER-SAFE sentence (e.g. 'permissions.denied.staff_only')
     * @param  array<string,mixed>  $replace
     */
    public static function deny(string $reasonKey, array $replace = []): never
    {
        throw new self((string) __($reasonKey, $replace));
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->reason], 403);
        }

        return response()->view('errors.friendly-denial', ['reason' => $this->reason], 403);
    }
}
