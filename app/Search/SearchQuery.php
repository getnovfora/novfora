<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Search;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A parsed, immutable search request (P2-M4 facets). Holds the keyword term plus the optional, combinable
 * facets — author, forum, date range, tags, type — and the viewer whose forum visibility gates every result.
 * Facet state is read from GET query params so a faceted search is bookmarkable. The driver-specific
 * translation (DB WHERE clauses vs. Meilisearch native filters) lives in SearchService; this object is the
 * driver-neutral description of "what was asked for".
 */
final class SearchQuery
{
    /**
     * @param  list<int>  $tagIds
     * @param  'post'|'topic'  $type  'post' = any post; 'topic' = opening posts only (one hit per thread)
     */
    public function __construct(
        public readonly User $viewer,
        public readonly string $term = '',
        public readonly ?int $authorId = null,
        public readonly ?int $forumId = null,
        public readonly ?Carbon $dateFrom = null,
        public readonly ?Carbon $dateTo = null,
        public readonly array $tagIds = [],
        public readonly string $type = 'post',
        public readonly int $limit = 25,
    ) {}

    /** Parse the search form's GET params into a query for $viewer. Unknown/blank facets are simply omitted. */
    public static function fromRequest(Request $request, User $viewer, int $limit = 25): self
    {
        // author is entered as a username (bookmarkable, human-friendly); resolve to an id. A given-but-unknown
        // username forces an empty result (id 0 matches no user) rather than silently ignoring the facet.
        $authorId = null;
        $authorName = trim((string) $request->query('author', ''));
        if ($authorName !== '') {
            $authorId = (int) (User::where('username', $authorName)->value('id') ?? 0);
        }

        $forumId = $request->filled('forum') ? (int) $request->query('forum') : null;

        $dateFrom = self::parseDate((string) $request->query('from', ''))?->startOfDay();
        $dateTo = self::parseDate((string) $request->query('to', ''))?->endOfDay();

        $tagIds = collect((array) $request->query('tags', []))
            ->map(fn ($t) => (int) $t)->filter()->values()->all();

        $type = $request->query('type') === 'topic' ? 'topic' : 'post';

        return new self(
            viewer: $viewer,
            term: trim((string) $request->query('q', '')),
            authorId: $authorId,
            forumId: $forumId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            tagIds: $tagIds,
            type: $type,
            limit: $limit,
        );
    }

    /** True when any facet (not just the keyword) narrows the search — used to decide layout / "clear" affordances. */
    public function hasFacets(): bool
    {
        return $this->authorId !== null || $this->forumId !== null || $this->dateFrom !== null
            || $this->dateTo !== null || $this->tagIds !== [] || $this->type !== 'post';
    }

    private static function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
