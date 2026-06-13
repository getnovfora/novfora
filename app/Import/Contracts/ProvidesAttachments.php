<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Contracts;

/**
 * Optional capability a {@see SourceDriver} MAY implement to import file ATTACHMENTS (ADR-0034 hardening).
 * Kept SEPARATE from SourceDriver so the addition is ADDITIVE (semver-safe): a driver — including a future
 * third-party one — that has no attachments simply doesn't implement this, and the ImportRunner skips the
 * stage. The driver resolves each legacy attachment's bytes from its own configured base directory and returns
 * the absolute `path`; the runner reads it, stores it on the app disk, records a sha-256 checksum, and verifies
 * by re-hashing — CONTENT verification, not just a row count.
 */
interface ProvidesAttachments
{
    /**
     * Attachments with source_id > $afterId, ascending, up to $limit. `path` is the absolute filesystem path to
     * the legacy file (the driver builds it from its configured attachments base dir + the legacy physical name).
     *
     * @return list<array{source_id:int, post_source_id:int, author_source_id:int, original_name:string, mime:string, path:string}>
     */
    public function attachments(int $afterId, int $limit): array;
}
