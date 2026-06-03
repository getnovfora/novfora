<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

/**
 * The full result of a resolution — the verdict plus the trace that powers the
 * "why can / can't this user do X?" inspector (security §1.4) and the test oracle.
 */
final class Decision
{
    /**
     * @param  string  $reason  banned | never | user_allow | group_allow | default
     * @param  list<array{holder:string,scope:string,value:string,note?:string}>  $trace
     */
    public function __construct(
        public bool $granted,
        public string $reason,
        public ?Scope $decidedAtScope = null,
        public ?string $decidedByHolder = null,
        public array $trace = [],
    ) {}

    public function summary(): string
    {
        $verdict = $this->granted ? 'ALLOWED' : 'DENIED';
        $where = $this->decidedAtScope?->key() ?? '—';

        return "{$verdict} (reason: {$this->reason}; decided by {$this->decidedByHolder} @ {$where})";
    }
}
