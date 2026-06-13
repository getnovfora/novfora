<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One queued/attempted delivery of a domain event to a webhook endpoint (ADR-0033). Created pending by the
 * dispatcher on the event; drained, signed, POSTed, and retried by the cron runner.
 *
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property array<string,mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int $max_attempts
 * @property int|null $response_status
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
