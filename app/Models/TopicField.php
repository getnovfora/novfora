<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-defined custom TOPIC field (U19 / NOV-116). Typed (text|url|textarea|select), optionally required,
 * and forum-SCOPED like a Prefix — a null `forum_id` is a global field shown on every forum, a set one applies
 * to just that forum. `options` holds a `select`'s choice list.
 *
 * @property int $id
 * @property ?int $forum_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property ?array<int,string> $options
 * @property bool $is_required
 * @property int $position
 * @property bool $is_active
 */
class TopicField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'position' => 'integer',
        'is_active' => 'boolean',
        'forum_id' => 'integer',
    ];

    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }
}
