<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spike P2 — webhook replay/idempotency ledger (data-model: mail_webhook_events). The unique `event_key`
 * (provider event id, or a sha256 of the raw body) lets a duplicate/replayed provider POST be acknowledged
 * without re-suppressing. created_at only (append-only).
 */
class MailWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
