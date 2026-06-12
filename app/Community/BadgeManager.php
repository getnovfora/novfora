<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\Badge;
use App\Support\Audit;
use App\Support\GroupColor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACP — all badge domain logic (the badge SFC is the UI + self-guard). Rules:
 *   • Name is required — empty name throws BadgeException.
 *   • Slug is computed from name on CREATE only (Str::slug, suffix -2/-3… if taken). Never mutated on
 *     update: the slug is the stable public identity (used in import/export and future badge URLs).
 *   • Criteria must pass BadgeService::validateCriteria — invalid criteria throws BadgeException.
 *   • Color is palette-validated (GroupColor::isValid); an out-of-palette token is stored as null.
 *   • Icon must be in ICON_TOKENS; an unrecognised token is stored as null.
 *   • Delete removes the badge's user_badges rows then the badge in a single DB transaction (no orphan
 *     award rows left behind).
 *   • All writes are audited (badge.created / badge.updated / badge.deleted).
 */
final class BadgeManager
{
    /**
     * The closed set of icon identifiers available for badge display. These correspond to the icon
     * names already in the x-ui.icon component set — only register an icon here once it exists there.
     *
     * @var list<string>
     */
    public const ICON_TOKENS = [
        'shield',
        'check',
        'check-circle',
        'flag',
        'pin',
        'user',
        'users',
        'message',
        'bell',
        'clock',
    ];

    /** @param array<string,mixed> $data */
    public function create(array $data): Badge
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new BadgeException('A badge name is required.');
        }

        $criteria = BadgeService::validateCriteria((array) ($data['criteria'] ?? []));
        if ($criteria === null) {
            throw new BadgeException('Invalid badge criteria.');
        }

        try {
            $badge = Badge::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $this->cleanDescription($data['description'] ?? null),
                'criteria' => $criteria,
                'icon_token' => $this->cleanIcon($data['icon_token'] ?? null),
                'color_token' => $this->cleanColor($data['color_token'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent creates raced uniqueSlug's check-then-insert — surface a friendly retry
            // instead of a 500 (the admin just resubmits; the next pass suffixes past the winner).
            throw new BadgeException('A badge with this name was just created — please try again.');
        }

        Audit::log('badge.created', $badge, ['name' => $name]);

        return $badge;
    }

    /** @param array<string,mixed> $data */
    public function update(Badge $badge, array $data): Badge
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new BadgeException('A badge name is required.');
        }

        $criteria = BadgeService::validateCriteria((array) ($data['criteria'] ?? []));
        if ($criteria === null) {
            throw new BadgeException('Invalid badge criteria.');
        }

        // Slug is the stable identity — never changed after creation even if the name changes.
        $badge->update([
            'name' => $name,
            'description' => $this->cleanDescription($data['description'] ?? null),
            'criteria' => $criteria,
            'icon_token' => $this->cleanIcon($data['icon_token'] ?? null),
            'color_token' => $this->cleanColor($data['color_token'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? $badge->is_active),
        ]);

        Audit::log('badge.updated', $badge, ['name' => $name]);

        return $badge->refresh();
    }

    /**
     * Delete a badge, removing all user_badges award rows first (no orphan rows).
     * Wrapped in a DB transaction so the sweep and the delete are atomic.
     */
    public function delete(Badge $badge): void
    {
        DB::transaction(function () use ($badge): void {
            DB::table('user_badges')->where('badge_id', $badge->id)->delete();
            $badge->delete();
        });

        Audit::log('badge.deleted', null, ['name' => $badge->name]);
    }

    /**
     * Derive a URL-safe slug from the name and resolve any collision by suffixing -2, -3, … until a
     * free slot is found. The slug is assigned once at creation and never touched again.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            // A symbol/emoji-only name slugs to nothing — an empty string cannot be the stable identity.
            throw new BadgeException('A badge name must contain letters or numbers.');
        }

        $candidate = $base;
        $suffix = 2;

        while (Badge::where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function cleanDescription(mixed $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }

        // Hard-capped to the column width (VARCHAR 255) — strict-mode MySQL would reject, not truncate.
        $trimmed = Str::limit(trim((string) $description), 255, '');

        return $trimmed !== '' ? $trimmed : null;
    }

    private function cleanColor(mixed $token): ?string
    {
        $token = is_string($token) ? trim($token) : null;

        return GroupColor::isValid($token) ? $token : null;
    }

    private function cleanIcon(mixed $token): ?string
    {
        $token = is_string($token) ? trim($token) : null;

        return ($token !== null && $token !== '' && in_array($token, self::ICON_TOKENS, true)) ? $token : null;
    }
}
