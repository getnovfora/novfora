<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Api\OpenApiGenerator;
use App\Models\ApiToken;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Admin-API spine's proof surface (E1 / NOV-135, ADR-0115). These three endpoints exercise the full trust
 * chain end-to-end — token auth → scope check → `canDo` → envelope → idempotency → audit-via-token — so the
 * spine is verifiable before E2/E3 add the real resource endpoints.
 */
final class AdminV1Controller extends AdminApiController
{
    /** GET /whoami — identity + the token's scopes (read proof: auth ∩ scope ∩ canDo). */
    public function whoami(Request $request): JsonResponse
    {
        $user = $this->requireCapability('admin.access');
        $token = $request->attributes->get('api_token');

        return $this->data([
            'user' => ['id' => $user->getKey(), 'username' => $user->username],
            'token' => [
                'name' => $token instanceof ApiToken ? $token->name : null,
                'scopes' => $token instanceof ApiToken ? ($token->scopes ?? []) : [],
            ],
        ]);
    }

    /** POST /ping — a benign audited liveness write (write proof: scope + idempotency + via-token audit). */
    public function ping(): JsonResponse
    {
        $this->requireCapability('admin.access');
        Audit::log('api.ping');

        return $this->data(['ok' => true, 'at' => now()->toIso8601String()]);
    }

    /** GET /openapi.json — the OpenAPI 3.1 contract scaffold, gated to admin-scoped tokens (not member tokens). */
    public function openapi(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        abort_unless($token instanceof ApiToken && $token->isAdminScoped(), 403);

        return response()->json(app(OpenApiGenerator::class)->document());
    }
}
