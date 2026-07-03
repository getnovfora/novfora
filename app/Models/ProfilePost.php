<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Community\WallService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A profile wall / status post (◆-lite). Belongs to two users: the wall OWNER (profile_user_id) and the
 * AUTHOR (user_id) who wrote it. body_canonical is the lossless TipTap source (like a post); body_html_cache
 * is the pre-sanitised display HTML produced by {@see WallService}. Never render body_canonical
 * or any raw HTML directly — only body_html_cache, which has already been through the sanitiser.
 */
final class ProfilePost extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'body_canonical' => 'array', // lossless TipTap doc, mirroring Post
    ];

    /** @return BelongsTo<User, $this> the user whose wall this sits on */
    public function profileUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_user_id');
    }

    /** @return BelongsTo<User, $this> the author who wrote the status */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
