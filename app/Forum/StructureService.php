<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Permissions\AclVersion;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * The forum structure manager's domain service (ACP v1, PART 2): create / edit / reorder / delete
 * categories, boards and sub-boards, with the binding DELETE-SAFETY rule — a node with content can only
 * be deleted by first MOVING that content to a destination board (never silent destruction). Counters
 * are recomputed authoritatively after a move so they can't drift, and every structural change is
 * audited. Reparenting rebuilds the materialised path/depth for the whole subtree and is cycle-checked.
 *
 * Default permissions for a NEW board: none are written at the node — a board inherits the global role
 * presets through the scope chain (Guests = view, Members = post, Moderators = moderate, Admins = admin),
 * so it is immediately usable the moment it is created (ADR-0006). Per-board overrides are an ACL edit
 * via the permission inspector, reached from each row.
 */
class StructureService
{
    public function __construct(private readonly AclVersion $acl) {}

    /**
     * Create a category or board. A category is always top-level; a board may sit under a category, under
     * another board (a sub-board), or at the top level. Position is appended within the sibling set.
     *
     * @param  array{title:string,type:string,description?:?string,parent_id?:int|string|null}  $data
     */
    public function create(array $data): Forum
    {
        $type = $data['type'] === 'category' ? 'category' : 'forum';
        $parentId = $this->normaliseParentId($data['parent_id'] ?? null);

        if ($type === 'category' && $parentId !== null) {
            throw new StructureException('Categories are top-level — they cannot have a parent.');
        }
        if ($parentId !== null && ! Forum::whereKey($parentId)->exists()) {
            throw new StructureException('The chosen parent no longer exists.');
        }

        $forum = Forum::create([
            'title' => trim($data['title']),
            'slug' => $this->uniqueSlug($data['title']),
            'description' => $this->cleanDescription($data['description'] ?? null),
            'type' => $type,
            'parent_id' => $parentId,
            'position' => (int) (Forum::where('parent_id', $parentId)->max('position') ?? -1) + 1,
        ]);

        Audit::log('forum.created', $forum, ['type' => $type, 'parent_id' => $parentId]);

        return $forum;
    }

    /**
     * Update a node's title / description / parent (reparenting rebuilds paths + is cycle-checked).
     *
     * @param  array{title:string,description?:?string,parent_id?:int|string|null}  $data
     */
    public function update(Forum $forum, array $data): Forum
    {
        $forum->title = trim($data['title']);
        if (array_key_exists('description', $data)) {
            $forum->description = $this->cleanDescription($data['description']);
        }

        $reparented = false;
        if (array_key_exists('parent_id', $data)) {
            $newParentId = $this->normaliseParentId($data['parent_id']);
            if ($newParentId !== ($forum->parent_id === null ? null : (int) $forum->parent_id)) {
                if ($forum->isCategory() && $newParentId !== null) {
                    throw new StructureException('Categories are top-level — they cannot have a parent.');
                }
                $this->assertAcyclic($forum, $newParentId);
                $forum->parent_id = $newParentId;
                $reparented = true;
            }
        }

        $forum->save(); // fires the model's parent_id/path change → AclVersion bump

        if ($reparented) {
            $this->rebuildPaths($forum);
        }

        Audit::log('forum.updated', $forum, ['reparented' => $reparented]);

        return $forum;
    }

    /** Move a node one slot up or down within its sibling set, re-sequencing sibling positions. */
    public function reorder(Forum $forum, string $direction): void
    {
        $siblings = Forum::where('parent_id', $forum->parent_id)
            ->orderBy('position')->orderBy('id')->get()->values();

        $i = $siblings->search(fn (Forum $s): bool => (int) $s->id === (int) $forum->id);
        if ($i === false) {
            return;
        }
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $siblings->count()) {
            return; // already at the end
        }

        $reordered = $siblings->all();
        [$reordered[$i], $reordered[$j]] = [$reordered[$j], $reordered[$i]];
        foreach ($reordered as $pos => $sibling) {
            $sibling->forceFill(['position' => $pos])->saveQuietly();
        }
    }

    /**
     * Delete a node, enforcing the safety rule. A node with sub-items must have them moved/deleted first.
     * A node with topics requires a destination board; its topics (incl. recycle-bin ones) are moved there
     * and both forums' counters recomputed, then the node is soft-deleted. Returns the moved-topic count.
     */
    public function delete(Forum $forum, ?Forum $destination = null): int
    {
        if ($forum->children()->exists()) {
            throw new StructureException("This {$forum->type} still has sub-items — move or delete them first.");
        }

        $moved = 0;
        $topicCount = Topic::withTrashed()->where('forum_id', $forum->id)->count();
        if ($topicCount > 0) {
            if (! $destination instanceof Forum) {
                throw new StructureException('This board still has topics — choose a destination board to move them into.');
            }
            if ($destination->is($forum) || $destination->isCategory()) {
                throw new StructureException('Pick a different destination board (not this node, and not a category).');
            }
            $moved = $this->moveContents($forum, $destination);
        }

        $forum->delete(); // soft delete → AclVersion bump (model event)

        Audit::log('forum.deleted', $forum, [
            'type' => $forum->type,
            'moved_topics' => $moved,
            'destination' => $destination?->id,
        ]);

        return $moved;
    }

    /**
     * Move EVERY topic (including recycle-bin ones) from one board to another, then recompute both boards'
     * counters from scratch so they can't drift. Reuses the engine's topology-change invalidation by
     * bumping the ACL version once. Returns the number of topics moved.
     */
    public function moveContents(Forum $from, Forum $to): int
    {
        $moved = Topic::withTrashed()->where('forum_id', $from->id)->count();

        Topic::withTrashed()->where('forum_id', $from->id)->update(['forum_id' => $to->id]);

        $this->acl->bump(); // a topic changed scope → resolved-permission caches must invalidate (security §1.5)
        $this->recount($from);
        $this->recount($to);

        if ($moved > 0) {
            Audit::log('forum.contents_moved', $to, ['from' => $from->id, 'topics' => $moved]);
        }

        return $moved;
    }

    /**
     * Recompute a forum's denormalised counters + last-post pointers authoritatively from its current
     * (non-trashed) content — the same figures the index renders, applied identically to both sides of a
     * move so totals are conserved.
     */
    public function recount(Forum $forum): void
    {
        $topicIds = Topic::where('forum_id', $forum->id)->pluck('id');
        $postCount = $topicIds->isEmpty() ? 0 : Post::whereIn('topic_id', $topicIds)->count();

        $activeTopic = Topic::where('forum_id', $forum->id)
            ->whereNotNull('last_posted_at')
            ->orderByDesc('last_posted_at')
            ->first();

        $forum->forceFill([
            'topic_count' => $topicIds->count(),
            'post_count' => $postCount,
            'last_post_id' => $activeTopic?->last_post_id,
            'last_topic_id' => $activeTopic?->getKey(),
            'last_posted_at' => $activeTopic?->last_posted_at,
        ])->saveQuietly();
    }

    /** Rebuild the materialised path + depth for a node and its whole subtree (after a reparent). */
    private function rebuildPaths(Forum $node): void
    {
        $parent = $node->parent_id ? Forum::find($node->parent_id) : null;
        $node->forceFill([
            'path' => ($parent ? $parent->path : '/').$node->id.'/',
            'depth' => $parent ? (int) $parent->depth + 1 : 0,
        ])->saveQuietly();

        foreach ($node->children()->get() as $child) {
            $this->rebuildPaths($child);
        }
    }

    private function assertAcyclic(Forum $node, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }
        if ($newParentId === (int) $node->id) {
            throw new StructureException("A node can't be its own parent.");
        }
        $newParent = Forum::find($newParentId);
        if (! $newParent instanceof Forum) {
            throw new StructureException('The chosen parent no longer exists.');
        }
        // The node's id appears as a path segment of any descendant; if the proposed parent is a
        // descendant, reparenting would create a cycle.
        if (str_contains((string) $newParent->path, '/'.$node->id.'/')) {
            throw new StructureException("You can't move a node into one of its own sub-items.");
        }
    }

    private function normaliseParentId(int|string|null $parentId): ?int
    {
        if ($parentId === null || $parentId === '' || (int) $parentId === 0) {
            return null;
        }

        return (int) $parentId;
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);

        return $description === '' ? null : $description;
    }

    /** A URL-safe, collision-free slug derived from the title (suffix -2, -3, … on conflict). */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'forum';
        $slug = $base;
        $n = 1;
        while (Forum::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
