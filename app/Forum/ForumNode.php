<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Permissions\Scope;

/**
 * A cache-safe, read-only projection of one forum/category node in the index tree (RH-9).
 *
 * The forum index fragment-caches its category tree. config/cache.php hardens the cache with
 * `serializable_classes => false` (anti object-injection), so on a SERIALIZING store — database / file /
 * redis, i.e. every real deployment — any cached OBJECT (an Eloquent model, a Collection, a Carbon, or
 * even a plain value object) comes back as a `__PHP_Incomplete_Class` on read and 500s the view. The fix
 * is therefore NOT to cache instances of this class: the controller caches a plain SCALAR ARRAY tree
 * ({@see toArray()}) and rehydrates these value objects ({@see fromArray()}) AFTER the cache boundary, so
 * no object is ever serialized.
 *
 * Property names mirror the {@see Forum} Eloquent attributes (`topic_count`, `post_count`, …) on purpose,
 * so the shared `forum.partials.forum-row` template renders a node or a model identically.
 */
final class ForumNode
{
    /** @param  list<self>  $children */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $topic_count,
        public readonly int $post_count,
        public readonly array $children = [],
    ) {}

    /**
     * Project a (children-eager-loaded) Forum into the primitive array shape that gets cached — scalars
     * and nested arrays only, exactly what {@see fromArray()} reads back.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Forum $forum): array
    {
        return [
            'id' => (int) $forum->id,
            'type' => (string) $forum->type,
            'title' => (string) $forum->title,
            'description' => $forum->description !== null ? (string) $forum->description : null,
            'topic_count' => (int) $forum->topic_count,
            'post_count' => (int) $forum->post_count,
            'children' => $forum->relationLoaded('children')
                ? $forum->children->map(static fn (Forum $child): array => self::toArray($child))->all()
                : [],
        ];
    }

    /**
     * Rehydrate a node (and its subtree) from the primitive array read back out of the cache.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        /** @var list<array<string, mixed>> $children */
        $children = $row['children'] ?? [];

        return new self(
            id: (int) $row['id'],
            type: (string) $row['type'],
            title: (string) $row['title'],
            description: isset($row['description']) ? (string) $row['description'] : null,
            topic_count: (int) ($row['topic_count'] ?? 0),
            post_count: (int) ($row['post_count'] ?? 0),
            children: array_map(static fn (array $child): self => self::fromArray($child), $children),
        );
    }

    public function isCategory(): bool
    {
        return $this->type === 'category';
    }

    /** The permission-engine scope this node represents — mirrors {@see Forum::permissionScope()}. */
    public function permissionScope(): Scope
    {
        return $this->isCategory() ? Scope::category($this->id) : Scope::forum($this->id);
    }
}
