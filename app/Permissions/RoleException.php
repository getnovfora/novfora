<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use RuntimeException;

/**
 * ACP v3 · v3-d — a custom-role-builder safety rule was violated: editing or deleting a read-only system preset,
 * an empty role name, or (the apex concern) an escalation/self-lockout attempt — a non-admin minting or assigning
 * an Administration-tier key, granting beyond their own ceiling, or stripping the administrators group's own
 * admin access. The role-builder SFC catches it and surfaces the message; the rule is enforced in the service,
 * never only in the UI (mirrors GroupException / the GroupPermissionEditor backstop throw).
 */
final class RoleException extends RuntimeException {}
