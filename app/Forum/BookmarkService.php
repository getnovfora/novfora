<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Bookmark;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Personal bookmarks ("saved" topics + posts) — member tool 2.1. Bookmarks are a private edge owned by the
 * user (no permission key; saving is ungated participation, like a draft). The single writer for the toggle
 * UI + the "saved" view. The UI passes a short KIND string ('topic' | 'post'); the class map is the only
 * place a kind maps to a model — the UI never names a class.
 */
final class BookmarkService
{
    /** @var array<string, class-string<Model>> */
    private const KINDS = ['topic' => Topic::class, 'post' => Post::class];

    /** Resolve a UI kind+id to its model, or null if the kind is unknown / the row is gone. */
    public function resolve(string $kind, int $id): ?Model
    {
        $class = self::KINDS[$kind] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    /** Toggle a bookmark; returns the resulting state (true = now saved). */
    public function toggle(User $user, Model $target): bool
    {
        $existing = $this->query($user, $target)->first();
        if ($existing instanceof Bookmark) {
            $existing->delete();

            return false;
        }

        try {
            Bookmark::create([
                'user_id' => $user->getKey(),
                'bookmarkable_type' => $target->getMorphClass(),
                'bookmarkable_id' => $target->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the double-click race — it is already saved, which is the state we want.
        }

        return true;
    }

    public function isBookmarked(User $user, Model $target): bool
    {
        return $this->query($user, $target)->exists();
    }

    /**
     * Batch lookup for a list page (e.g. every post in a topic): which of $ids the user has saved.
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     * @return array<int,bool> id => true (only saved ids present)
     */
    public function bookmarkedIds(User $user, string $class, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Bookmark::query()
            ->where('user_id', $user->getKey())
            ->where('bookmarkable_type', (new $class)->getMorphClass())
            ->whereIn('bookmarkable_id', $ids)
            ->pluck('bookmarkable_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * The user's bookmarks, newest first, with the saved target eager-loaded (paginator).
     *
     * @return LengthAwarePaginator<int, Bookmark>
     */
    public function paginate(User $user, int $perPage = 20)
    {
        return Bookmark::query()
            ->where('user_id', $user->getKey())
            ->with('bookmarkable')
            ->latest()
            ->paginate($perPage);
    }

    /** @return Builder<Bookmark> */
    private function query(User $user, Model $target)
    {
        return Bookmark::query()
            ->where('user_id', $user->getKey())
            ->where('bookmarkable_type', $target->getMorphClass())
            ->where('bookmarkable_id', $target->getKey());
    }
}
