<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Tag;
use App\Models\Topic;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tag domain service (P2-M1). Authorization is the caller's responsibility — this service performs no HTTP
 * auth checks, matching PostService/PrefixManager. All usage_count values are recomputed authoritatively from
 * the taggables table after every sync (drift-free, mirroring ReactionService::recountType).
 */
final class TagService
{
    private const MAX_NAME_LENGTH = 50;

    /**
     * Normalise a raw tag name string: strip HTML, collapse whitespace, trim, bound to max length.
     */
    public function normalizeName(string $name): string
    {
        $name = strip_tags($name);
        $name = (string) preg_replace('/\s+/', ' ', $name);
        $name = trim($name);

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Generate a URL slug for a tag name.
     */
    public function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Fetch tags that already exist in the database, matched by slug from the given names.
     *
     * @param  array<int,string>  $names
     * @return Collection<int,Tag>
     */
    public function existing(array $names): Collection
    {
        $slugs = array_values(array_filter(array_map(
            fn (string $n): string => $this->slugFor($this->normalizeName($n)),
            $names,
        ), fn (string $s): bool => $s !== ''));

        if ($slugs === []) {
            return collect();
        }

        return Tag::whereIn('slug', $slugs)->get();
    }

    /**
     * Mint a brand-new tag (the caller must have verified tag.create permission before calling).
     * Deduplicates by slug: if a tag with this slug already exists it is returned as-is.
     */
    public function create(string $name): Tag
    {
        $name = $this->normalizeName($name);
        $slug = $this->slugFor($name);

        if ($slug === '') {
            throw new TagException('A tag name is required.');
        }

        $existing = Tag::where('slug', $slug)->first();
        if ($existing instanceof Tag) {
            return $existing;
        }

        $tag = Tag::create(['name' => $name, 'slug' => $slug, 'usage_count' => 0]);
        Audit::log('tag.created', $tag, ['name' => $name, 'slug' => $slug]);

        return $tag;
    }

    /**
     * Sync a topic's tags to exactly the given tag ids (replacing the previous set). Recomputes
     * usage_count authoritatively for every affected tag (old ∪ new) — no blind increment/decrement.
     *
     * @param  array<int,int>  $tagIds
     */
    public function syncTopicTags(Topic $topic, array $tagIds): void
    {
        // Collect the old set before the sync so we can recount them too.
        $oldTagIds = $topic->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all();

        $topic->tags()->sync($tagIds);

        $affectedIds = array_values(array_unique(array_merge($oldTagIds, $tagIds)));
        foreach ($affectedIds as $id) {
            $this->recount((int) $id);
        }

        Audit::log('topic.tags_synced', $topic, ['tag_ids' => $tagIds]);
    }

    /**
     * Authoritatively recompute a tag's usage_count from the taggables table (drift-free).
     */
    private function recount(int $tagId): void
    {
        $count = DB::table('taggables')->where('tag_id', $tagId)->count();
        Tag::where('id', $tagId)->update(['usage_count' => $count]);
    }
}
