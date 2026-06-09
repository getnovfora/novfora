<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Prefix;
use App\Models\Topic;
use App\Support\Audit;
use App\Support\GroupColor;
use Illuminate\Support\Facades\DB;

/**
 * ACP — all prefix domain logic (the prefix SFC is the UI + self-guard). Rules:
 *   • Label is required — empty label throws PrefixException.
 *   • Colour is palette-validated (GroupColor::isValid); an out-of-palette token is stored as null.
 *   • Delete nulls topics.prefix_id for affected topics (no orphan), then removes the prefix.
 *   • All writes are audited (prefix.created / prefix.updated / prefix.deleted).
 */
final class PrefixManager
{
    /** @param array<string,mixed> $data */
    public function create(array $data): Prefix
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new PrefixException('A prefix label is required.');
        }

        $prefix = Prefix::create([
            'forum_id' => isset($data['forum_id']) && $data['forum_id'] !== '' ? (int) $data['forum_id'] : null,
            'label' => $label,
            'color_token' => $this->cleanColor($data['color_token'] ?? null),
            'position' => max(0, (int) ($data['position'] ?? 0)),
        ]);

        Audit::log('prefix.created', $prefix, ['label' => $label]);

        return $prefix;
    }

    /** @param array<string,mixed> $data */
    public function update(Prefix $prefix, array $data): Prefix
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new PrefixException('A prefix label is required.');
        }

        $prefix->update([
            'forum_id' => isset($data['forum_id']) && $data['forum_id'] !== '' ? (int) $data['forum_id'] : null,
            'label' => $label,
            'color_token' => $this->cleanColor($data['color_token'] ?? null),
            'position' => max(0, (int) ($data['position'] ?? $prefix->position)),
        ]);

        Audit::log('prefix.updated', $prefix, ['label' => $label]);

        return $prefix->refresh();
    }

    /**
     * Delete a prefix, nulling topics.prefix_id for any topics that use it (no orphan).
     * Wrapped in a DB transaction so the null-out and the delete are atomic.
     */
    public function delete(Prefix $prefix): void
    {
        DB::transaction(function () use ($prefix): void {
            Topic::where('prefix_id', $prefix->id)->update(['prefix_id' => null]);
            $prefix->delete();
        });

        Audit::log('prefix.deleted', null, ['label' => $prefix->label]);
    }

    /**
     * Reorder prefixes by providing an ordered list of ids. Each prefix gets its new position (0-based index).
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            Prefix::where('id', $id)->update(['position' => $position]);
        }
    }

    private function cleanColor(mixed $token): ?string
    {
        $token = is_string($token) ? trim($token) : null;

        return GroupColor::isValid($token) ? $token : null;
    }
}
