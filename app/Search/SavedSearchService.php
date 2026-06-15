<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Search;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The own-only authority for a member's saved searches (search 6.1). Every read/write is scoped to the owning
 * user, so a saved-search id can never be acted on across accounts. Saving is ungated participation (no ACL).
 */
final class SavedSearchService
{
    /** Per-user cap so the list stays sane. */
    public const MAX_PER_USER = 50;

    public function save(User $user, string $name, string $term, string $queryString): SavedSearch
    {
        return SavedSearch::create([
            'user_id' => $user->getKey(),
            'name' => trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Saved search',
            'term' => mb_substr($term, 0, 500),
            'query_string' => mb_substr($queryString, 0, 1000),
        ]);
    }

    /** @return Collection<int,SavedSearch> the user's saved searches, newest first */
    public function list(User $user): Collection
    {
        return SavedSearch::query()->where('user_id', $user->getKey())->latest()->get();
    }

    public function count(User $user): int
    {
        return SavedSearch::query()->where('user_id', $user->getKey())->count();
    }

    /** Delete one of the user's own saved searches. Returns true when a row was removed. */
    public function delete(User $user, int $id): bool
    {
        return SavedSearch::query()->where('user_id', $user->getKey())->whereKey($id)->delete() > 0;
    }
}
