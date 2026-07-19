<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One topic's value for one {@see TopicField} (U19 / NOV-116). No timestamps (mirrors CustomFieldValue); the
 * pair (topic_id, topic_field_id) is unique so a value is upserted, never duplicated.
 *
 * @property int $id
 * @property int $topic_id
 * @property int $topic_field_id
 * @property ?string $value
 */
class TopicFieldValue extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<TopicField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(TopicField::class, 'topic_field_id');
    }
}
