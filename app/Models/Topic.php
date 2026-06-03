<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Topic extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // A thread is a scope: deleting it or moving it to another forum changes resolution at that
        // scope, so it invalidates resolved-permission caches (security §1.5), like an ACL change.
        static::deleted(fn () => app(AclVersion::class)->bump());
        static::updated(function (Topic $topic) {
            if ($topic->wasChanged('forum_id')) {
                app(AclVersion::class)->bump();
            }
        });
    }

    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }
}
