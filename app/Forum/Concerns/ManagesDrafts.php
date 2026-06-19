<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum\Concerns;

use App\Models\PostDraft;
use App\Models\User;

/**
 * Editor-autosave behaviour for a Livewire compose component (P2-M1). The host component supplies the draft
 * CONTEXT (a coarse compose surface) and owns a `public array $canonicalJson` editor property; this trait
 * provides the debounced-network autosave action, the mount-time restore, and discard-on-publish.
 *
 * OWN-ONLY by construction: every operation is scoped to `auth()->user()` + the context, and no draft id is
 * ever accepted from or exposed to the client — so a user can only ever read/write their own draft. The
 * autosave action receives ONLY the canonical doc (already synced to the editor), never a draft identifier.
 */
trait ManagesDrafts
{
    /** True once a saved draft was restored into the editor on mount (drives the "draft restored" hint). */
    public bool $draftRestored = false;

    /**
     * The draft context for this component: [string $type, int $id]. e.g. ['reply', $topicId].
     *
     * @return array{0:string,1:int}
     */
    abstract protected function draftContext(): array;

    /**
     * Debounced network autosave from the editor island ($wire.saveDraft). Own-only; an empty document
     * discards rather than persisting a blank draft. Guests never autosave.
     *
     * @param  array<string,mixed>  $canonical
     */
    public function saveDraft(array $canonical): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        if ($this->docIsBlank($canonical)) {
            $this->discardDraft();

            return;
        }

        [$type, $id] = $this->draftContext();

        PostDraft::updateOrCreate(
            ['user_id' => $user->getKey(), 'context_type' => $type, 'context_id' => $id],
            ['body_format' => 'tiptap_json', 'body_canonical' => $canonical, 'tenant_id' => null],
        );
    }

    /** Restore the user's draft for this context into the editor property (call in mount, after auth). */
    protected function restoreDraft(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        [$type, $id] = $this->draftContext();

        $draft = PostDraft::query()
            ->where('user_id', $user->getKey())
            ->where('context_type', $type)
            ->where('context_id', $id)
            ->first();

        if ($draft instanceof PostDraft
            && is_array($draft->body_canonical)
            && ! $this->docIsBlank($draft->body_canonical)) {
            $this->canonicalJson = $draft->body_canonical;
            $this->format = 'tiptap_json'; // drafts are autosaved from the rich-text editor only
            $this->draftRestored = true;
        }
    }

    /** Remove the user's draft for this context (after a successful publish, or explicit discard). Own-only. */
    public function discardDraft(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        [$type, $id] = $this->draftContext();

        PostDraft::query()
            ->where('user_id', $user->getKey())
            ->where('context_type', $type)
            ->where('context_id', $id)
            ->delete();

        $this->draftRestored = false;
    }

    /**
     * True when a TipTap doc has no real content. An "empty" editor still emits a doc whose content is
     * [{type:'paragraph'}] — a non-empty array, so empty($content) is false even though it is blank to the
     * user (BUG-014). Blank = no text node with non-whitespace text AND no atomic content node (image /
     * mention / horizontalRule / embed) anywhere in the tree.
     *
     * @param  array<string,mixed>|null  $canonical
     */
    protected function docIsBlank(?array $canonical): bool
    {
        return ! is_array($canonical)
            || empty($canonical['content'])
            || ! $this->hasContent($canonical['content']);
    }

    /**
     * Recursively scan TipTap nodes for any non-whitespace text or atomic content node.
     */
    private function hasContent(mixed $nodes): bool
    {
        if (! is_array($nodes)) {
            return false;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'text') {
                if (trim((string) ($node['text'] ?? '')) !== '') {
                    return true;
                }
            } elseif (in_array($type, ['image', 'mention', 'horizontalRule', 'embed'], true)) {
                return true; // atomic content — meaningful even without text
            }

            if (isset($node['content']) && $this->hasContent($node['content'])) {
                return true;
            }
        }

        return false;
    }
}
