<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Http\JsonResponse;

/**
 * Base for every Admin-API controller (E1 / NOV-135, ADR-0115). Provides the success envelope (`{data: …}`) and
 * enforces the CAPABILITY half of the trust rule: `RequireApiScope` middleware has already checked the token
 * carries the route's scope; here we additionally assert the OWNER's `canDo`, so the effective ability is always
 * `scope ∩ canDo`. Error envelopes (`{error: {code, message, fields?}}`) are produced centrally in bootstrap for
 * `api/admin/*` — a bare `abort(403)` here renders as the envelope.
 */
abstract class AdminApiController extends Controller
{
    /** @param array<string,mixed> $data */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    /** Assert the token owner holds $capability at global scope (the second half of scope ∩ canDo). */
    protected function requireCapability(string $capability): User
    {
        $user = request()->user();
        abort_unless($user instanceof User && $user->canDo($capability, Scope::global()), 403);

        return $user;
    }
}
