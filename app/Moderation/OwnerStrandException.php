<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Account\AccountDeletionException;
use App\Admin\AdminCoOwnerException;
use App\Permissions\BanChecker;

/**
 * Raised when an effective ban/suspend is refused because applying it would STRAND the owner tier — leaving the
 * forum with zero administrators or zero co-owners, an unrecoverable lockout (a banned owner is blocked by
 * {@see BanChecker} before ACL resolution, so they can never reach the panel to lift their own
 * ban). The front-end ban surfaces catch it and render a blocking message, no ban performed; the warning
 * auto-consequence path treats it as a signal to SUPPRESS the ban while still recording the warning.
 *
 * The ban-tier sibling of {@see AccountDeletionException} and {@see AdminCoOwnerException}
 * (the deletion / demote doors onto the SAME last-owner invariant, ADR-0086). A plain RuntimeException — distinct
 * from the HTTP 403 the authorisation/rank guards abort with — so callers surface it gracefully, never the UI alone.
 */
final class OwnerStrandException extends \RuntimeException {}
