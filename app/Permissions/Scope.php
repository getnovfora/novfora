<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

/** A point in the scope hierarchy: global → category → forum → thread (security §1.1). */
final readonly class Scope
{
    public function __construct(
        public string $type,   // global | category | forum | thread
        public ?int $id = null, // null only for global
    ) {}

    public static function global(): self
    {
        return new self('global', null);
    }

    public static function category(int $id): self
    {
        return new self('category', $id);
    }

    public static function forum(int $id): self
    {
        return new self('forum', $id);
    }

    public static function thread(int $id): self
    {
        return new self('thread', $id);
    }

    /**
     * A club scope (Phase 4 · M1.1). The factory exists here so models can name the scope; the resolution
     * semantics (parse() acceptance + the ScopeChain `global → club → forum` branch + club permission grants)
     * are wired in M1.2. No acl_entries use scope_type='club' until then.
     */
    public static function club(int $id): self
    {
        return new self('club', $id);
    }

    /**
     * Parse a human/CLI scope reference: "global", "category:3", "forum:2", "thread:1".
     *
     * @throws \InvalidArgumentException on an unknown scope type or a missing id
     */
    public static function parse(string $ref): self
    {
        $ref = trim($ref);
        if ($ref === 'global' || $ref === 'global:*') {
            return self::global();
        }

        [$type, $id] = array_pad(explode(':', $ref, 2), 2, null);
        $type = strtolower((string) $type);

        if (! in_array($type, ['category', 'forum', 'thread'], true) || ! is_numeric($id)) {
            throw new \InvalidArgumentException("Unrecognised scope reference: {$ref} (expected global|category:ID|forum:ID|thread:ID).");
        }

        return new self($type, (int) $id);
    }

    public function isGlobal(): bool
    {
        return $this->type === 'global';
    }

    public function key(): string
    {
        return $this->type.':'.($this->id ?? '*');
    }

    public function matches(string $scopeType, ?int $scopeId): bool
    {
        return $this->type === $scopeType && (int) $this->id === (int) $scopeId;
    }
}
