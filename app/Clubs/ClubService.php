<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Forum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Club lifecycle (Phase 4 · M1.1): create / update / delete. The founder becomes the first OWNER member on
 * create. Slug generation is collision-safe. M1.2 hooks the role→acl_entries projection; M1.4 attaches the
 * club's discussion forum. Authorization is the CALLER's responsibility (mirrors PostService).
 */
class ClubService
{
    /**
     * Create a club and seat the founder as its owner. Returns the persisted club.
     *
     * @param  array{name:string,tagline?:?string,description?:?string,privacy?:string,is_listed?:bool,color?:?string}  $attrs
     */
    public function create(User $founder, array $attrs): Club
    {
        return DB::transaction(function () use ($founder, $attrs): Club {
            $privacy = $this->normalisePrivacy($attrs['privacy'] ?? 'public');

            $club = Club::create([
                'name' => trim($attrs['name']),
                'slug' => $this->uniqueSlug($attrs['name']),
                'tagline' => $this->clean($attrs['tagline'] ?? null),
                'description' => $this->clean($attrs['description'] ?? null),
                'privacy' => $privacy,
                // A public club is always listed; otherwise honour the requested flag (default listed).
                'is_listed' => $privacy === 'public' ? true : (bool) ($attrs['is_listed'] ?? true),
                'color' => $this->cleanColor($attrs['color'] ?? null),
                'created_by' => $founder->getKey(),
                'member_count' => 1,
            ]);

            // M1.4: every club owns one discussion forum, reusing the existing forum/topic/post stack. It is a
            // top-level (parent_id=null) forum tagged with club_id; ScopeChain injects the club scope into its
            // resolution chain. It is excluded from the main board index (ForumController) by the club_id tag.
            $forum = Forum::create([
                'title' => $club->name,
                'slug' => 'club-'.$club->slug,
                'type' => 'forum',
                'parent_id' => null,
                'club_id' => $club->getKey(),
                'description' => $club->tagline,
                'position' => 0,
            ]);
            $club->forceFill(['forum_id' => $forum->getKey()])->save();

            $membership = ClubMembership::create([
                'club_id' => $club->getKey(),
                'user_id' => $founder->getKey(),
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // Mirror the owner role into club-scoped acl_entries so management/moderation resolve through the
            // permission engine (M1.2). The roster row above stays the source of truth.
            $projector = app(ClubRoleProjector::class);
            $projector->project($membership);
            // M1.5: seed the guests-group forum.view=NEVER at club scope for a closed/private club.
            $projector->projectPrivacy($club);

            return $club;
        });
    }

    /**
     * Update a club's editable attributes (not membership). Slug is immutable after creation (stable URLs).
     *
     * @param  array{name?:string,tagline?:?string,description?:?string,privacy?:string,is_listed?:bool,color?:?string}  $attrs
     */
    public function update(Club $club, array $attrs): Club
    {
        if (array_key_exists('name', $attrs)) {
            $club->name = trim($attrs['name']);
        }
        if (array_key_exists('tagline', $attrs)) {
            $club->tagline = $this->clean($attrs['tagline']);
        }
        if (array_key_exists('description', $attrs)) {
            $club->description = $this->clean($attrs['description']);
        }
        if (array_key_exists('color', $attrs)) {
            $club->color = $this->cleanColor($attrs['color']);
        }
        if (array_key_exists('privacy', $attrs)) {
            $club->privacy = $this->normalisePrivacy($attrs['privacy']);
        }
        if (array_key_exists('is_listed', $attrs)) {
            $club->is_listed = (bool) $attrs['is_listed'];
        }
        // A public club is always discoverable — never let it be unlisted.
        if ($club->privacy === 'public') {
            $club->is_listed = true;
        }

        $privacyChanged = $club->isDirty('privacy');
        $club->save();

        // M1.5: a privacy change flips the anonymous guests-NEVER gate on the club's discussion forum.
        if ($privacyChanged) {
            app(ClubRoleProjector::class)->projectPrivacy($club);
        }

        return $club;
    }

    /** Soft-delete a club. Its membership rows cascade only on hard delete; soft-delete keeps the roster for restore. */
    public function delete(Club $club): void
    {
        $club->delete();
    }

    private function normalisePrivacy(string $privacy): string
    {
        return in_array($privacy, Club::PRIVACIES, true) ? $privacy : 'public';
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function cleanColor(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    /** A URL-safe, collision-free slug derived from the name (falls back to "club" when empty). */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'club';
        }

        $slug = $base;
        $n = 1;
        while (Club::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
