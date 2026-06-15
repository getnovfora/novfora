<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Search;

use App\Models\Forum;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Parses inline SEARCH OPERATORS out of a raw query string (discovery/search 6.1), resolving them to the same
 * facet fields the search form uses. Supported: `author:<username>`, `in:<forum-slug>`, `tag:<tag-slug>`
 * (repeatable), `after:<date>`, `before:<date>`, `type:topic`. Quoted `"phrases"` and everything else become
 * the residual keyword term. Operators are driver-neutral — they populate SearchQuery, which SearchService
 * already translates to DB (or, on the enhanced tier, Meili) filters.
 *
 * A missing author/forum/tag resolves to id 0, which (like the form's author facet) forces an EMPTY result
 * rather than silently dropping the operator — "in:nope" should find nothing, not everything.
 *
 * SECURITY (resource exhaustion): operator VALUES are collected during the token loop with NO database work;
 * resolution happens ONCE after the loop — at most one lookup each for author and forum, one batched `whereIn`
 * for tags (capped at MAX_TAGS), and at most two date parses. So a crafted query with hundreds of
 * `tag:`/`author:`/`in:`/`after:` tokens can no longer amplify into hundreds of synchronous DB queries / date
 * parses on the public search endpoint — the cost is bounded by a small constant regardless of token count.
 *
 * @phpstan-type Parsed array{term:string, authorId:?int, forumId:?int, dateFrom:?Carbon, dateTo:?Carbon, tagIds:list<int>, type:?string}
 */
final class SearchQueryParser
{
    /** Upper bound on distinct tag operators honoured per query — bounds the batched whereIn. */
    private const MAX_TAGS = 16;

    /** @return array{term:string, authorId:?int, forumId:?int, dateFrom:?Carbon, dateTo:?Carbon, tagIds:list<int>, type:?string} */
    public static function parse(string $raw): array
    {
        $authorName = null;   // last-wins, like the form's single author facet
        $forumSlug = null;
        $afterStr = null;
        $beforeStr = null;
        $tagSlugs = [];
        $type = null;
        $residual = [];

        // Defensive length cap: a search query beyond this is abuse, not a real search — bound the work
        // before tokenising so a multi-KB ?q can't drive a huge token list / LIKE term.
        $raw = mb_substr($raw, 0, 512);

        // Pass 1 — tokenise and COLLECT operator values only. No DB, no date parsing in the loop.
        preg_match_all('/"[^"]*"|\S+/', $raw, $matches);
        foreach ($matches[0] as $token) {
            if (preg_match('/^(author|in|tag|after|before|type):(.+)$/i', (string) $token, $m) === 1) {
                $op = strtolower($m[1]);
                $value = trim($m[2], '"');
                if ($value === '') {
                    continue;
                }
                match ($op) {
                    'author' => $authorName = $value,
                    'in' => $forumSlug = $value,
                    'tag' => count($tagSlugs) < self::MAX_TAGS ? $tagSlugs[] = $value : null,
                    'after' => $afterStr = $value,
                    'before' => $beforeStr = $value,
                    'type' => $type = ($value === 'topic' ? 'topic' : 'post'),
                    default => null,
                };

                continue;
            }

            $residual[] = trim((string) $token, '"');
        }

        // Pass 2 — resolve once. Author/forum: a missing value → id 0 (force empty, never "see everything").
        $authorId = $authorName !== null ? (int) (User::query()->where('username', $authorName)->value('id') ?? 0) : null;
        $forumId = $forumSlug !== null ? (int) (Forum::query()->where('slug', $forumSlug)->value('id') ?? 0) : null;

        return [
            'term' => trim(implode(' ', array_filter($residual, fn ($t) => $t !== ''))),
            'authorId' => $authorId,
            'forumId' => $forumId,
            'dateFrom' => $afterStr !== null ? self::date($afterStr)?->startOfDay() : null,
            'dateTo' => $beforeStr !== null ? self::date($beforeStr)?->endOfDay() : null,
            'tagIds' => self::resolveTagIds($tagSlugs),
            'type' => $type,
        ];
    }

    /**
     * Resolve tag slugs to ids in ONE batched query. If tag operators were given but none resolve to a real
     * tag, return the [0] sentinel so the search still narrows to nothing (consistent with author/forum).
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private static function resolveTagIds(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $ids = Tag::query()->whereIn('slug', array_values(array_unique($slugs)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return $ids === [] ? [0] : $ids;
    }

    private static function date(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
