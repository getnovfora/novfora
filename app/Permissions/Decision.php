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
     * @param  list<array{holder:string,scope:string,value:string,note?:string,expires_at?:string}>  $trace
     * @param  ?\DateTimeInterface  $cacheUntil  the earliest TTL among the rows that fed this verdict (ACP v3 ·
     *                                           v3-0): the cached can() must not outlive it, so the expiry
     *                                           filter stays authoritative on the cached path even if the prune
     *                                           cron lags. null = no TTL row contributed → cache the normal span.
     */
    public function __construct(
        public bool $granted,
        public string $reason,
        public ?Scope $decidedAtScope = null,
        public ?string $decidedByHolder = null,
        public array $trace = [],
        public ?\DateTimeInterface $cacheUntil = null,
    ) {}

    public function summary(): string
    {
        $verdict = $this->granted ? 'ALLOWED' : 'DENIED';
        $where = $this->decidedAtScope?->key() ?? '—';

        return "{$verdict} (reason: {$this->reason}; decided by {$this->decidedByHolder} @ {$where})";
    }
}
