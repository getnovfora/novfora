<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content;

/**
 * Extracts the user ids @mentioned in a canonical document. The WYSIWYG editor stores each mention as a
 * `{type:"mention", attrs:{id, label}}` node (ADR-0005); this walks the tree and collects the ids so the
 * notifier can alert mentioned users. Markdown mode has no structured mention node, so it yields none.
 */
final class Mentions
{
    /**
     * @param  array<string,mixed>  $doc
     * @return list<int> unique mentioned user ids
     */
    public static function idsIn(array $doc): array
    {
        $ids = [];
        self::walk($doc['content'] ?? [], $ids);

        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    /**
     * @param  array<int,mixed>  $nodes
     * @param  list<int>  $ids
     */
    private static function walk(array $nodes, array &$ids): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'mention') {
                $ids[] = (int) ($node['attrs']['id'] ?? 0);
            }
            if (! empty($node['content']) && is_array($node['content'])) {
                self::walk($node['content'], $ids);
            }
        }
    }
}
