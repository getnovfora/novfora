<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound webhook endpoint (ADR-0033). The signing `secret` is encrypted at rest. Written only through
 * App\Webhooks\WebhookManager (which SSRF-guards the URL). Subscribed events drive which deliveries are created.
 *
 * @property string $url
 * @property string $secret
 * @property array<int,string> $events
 * @property bool $is_active
 * @property string|null $description
 */
class WebhookEndpoint extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'bool',
        ];
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
