<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

/**
 * What a {@see PermissionSync} run did — or, for a --dry-run, what it WOULD do. Built by a single code
 * path so the preview and the real run can never disagree (ADR-0036).
 */
final class PermissionSyncReport
{
    /** @var list<string> permission catalog keys newly inserted into the `permissions` reference table */
    public array $catalogAdded = [];

    /** @var array<string, list<string>> role slug => permission keys added to that preset role */
    public array $permissionsAdded = [];

    /** @var array<string, list<string>> group slug => permission keys whose global acl_entry was written */
    public array $entriesWritten = [];

    public function isNoop(): bool
    {
        return $this->catalogAdded === [] && $this->permissionsAdded === [] && $this->entriesWritten === [];
    }

    public function catalogCount(): int
    {
        return count($this->catalogAdded);
    }

    public function permissionsCount(): int
    {
        return array_sum(array_map('count', $this->permissionsAdded));
    }

    public function entriesCount(): int
    {
        return array_sum(array_map('count', $this->entriesWritten));
    }

    /** The headline number for the command summary + the upgrade audit line. */
    public function totalChanges(): int
    {
        return $this->catalogCount() + $this->permissionsCount() + $this->entriesCount();
    }
}
