<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Api;

/**
 * The Admin-API OpenAPI 3.1 contract (E1 / NOV-135, ADR-0115). A scaffold in E1 — it publishes the security
 * scheme (bearer `nvfa_` token), the full scope taxonomy, and the spine's proof endpoints; E2/E3 extend the
 * `paths` map as resource endpoints land. Served token-gated at `/api/admin/v1/openapi.json`.
 */
final class OpenApiGenerator
{
    /** @return array<string,mixed> */
    public function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'NovFora Admin API',
                'version' => '1',
                'description' => 'Scoped administrative REST API. Effective ability = token scopes ∩ owner canDo.',
            ],
            'servers' => [['url' => url('/api/admin/v1')]],
            'components' => [
                'securitySchemes' => [
                    'adminToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'An `nvfa_`-prefixed admin-scoped API token, sent as `Authorization: Bearer …`.',
                    ],
                ],
            ],
            'security' => [['adminToken' => []]],
            'x-scopes' => ApiScopes::ALL,
            'paths' => [
                '/whoami' => ['get' => [
                    'summary' => 'Identity + token scopes',
                    'x-scope' => 'admin:maintenance',
                    'responses' => ['200' => ['description' => 'The authenticated admin + token scopes']],
                ]],
                '/ping' => ['post' => [
                    'summary' => 'Audited liveness ping',
                    'x-scope' => 'admin:maintenance',
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/openapi.json' => ['get' => [
                    'summary' => 'This contract document',
                    'responses' => ['200' => ['description' => 'The OpenAPI document']],
                ]],
            ],
        ];
    }
}
