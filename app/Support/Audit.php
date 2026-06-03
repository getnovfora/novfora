<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail helper (security §3). Records who did what to which record. */
final class Audit
{
    /**
     * @param  array<string,mixed>  $changes
     */
    public static function log(string $action, ?Model $auditable = null, array $changes = []): void
    {
        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'changes' => $changes !== [] ? $changes : null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
