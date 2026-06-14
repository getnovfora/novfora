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
 * @phpstan-type Parsed array{term:string, authorId:?int, forumId:?int, dateFrom:?Carbon, dateTo:?Carbon, tagIds:list<int>, type:?string}
 */
final class SearchQueryParser
{
    /** @return array{term:string, authorId:?int, forumId:?int, dateFrom:?Carbon, dateTo:?Carbon, tagIds:list<int>, type:?string} */
    public static function parse(string $raw): array
    {
        $authorId = null;
        $forumId = null;
        $dateFrom = null;
        $dateTo = null;
        $tagIds = [];
        $type = null;
        $residual = [];

        // Tokenise on whitespace, keeping "quoted phrases" together.
        preg_match_all('/"[^"]*"|\S+/', $raw, $matches);
        foreach ($matches[0] as $token) {
            if (preg_match('/^(author|in|tag|after|before|type):(.+)$/i', (string) $token, $m) === 1) {
                $op = strtolower($m[1]);
                $value = trim($m[2], '"');
                if ($value === '') {
                    continue;
                }
                match ($op) {
                    'author' => $authorId = (int) (User::query()->where('username', $value)->value('id') ?? 0),
                    'in' => $forumId = (int) (Forum::query()->where('slug', $value)->value('id') ?? 0),
                    'tag' => $tagIds[] = (int) (Tag::query()->where('slug', $value)->value('id') ?? 0),
                    'after' => $dateFrom = self::date($value)?->startOfDay(),
                    'before' => $dateTo = self::date($value)?->endOfDay(),
                    'type' => $type = ($value === 'topic' ? 'topic' : 'post'),
                    default => null,
                };

                continue;
            }

            $residual[] = trim((string) $token, '"');
        }

        return [
            'term' => trim(implode(' ', array_filter($residual, fn ($t) => $t !== ''))),
            'authorId' => $authorId,
            'forumId' => $forumId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'tagIds' => $tagIds,
            'type' => $type,
        ];
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
