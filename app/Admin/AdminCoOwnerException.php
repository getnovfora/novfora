<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

/**
 * ACP v3 · v3-a (ADR-0080) — a co-owner invariant was violated: appointing a non-admin, acting without being a
 * co-owner, or (the apex concern) removing/demoting the LAST co-owner, which would strand the forum with zero
 * owners. The Security SFC catches it and surfaces the message; the rule is enforced in AdminCoOwnerService as
 * the actor-independent backstop, never only in the UI (mirrors RoleException / AccountDeletionException).
 */
final class AdminCoOwnerException extends \RuntimeException {}
