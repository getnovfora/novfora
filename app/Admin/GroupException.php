<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use RuntimeException;

/**
 * ACP v2 — a binding group-management rule was violated (deleting a system group, deleting a non-empty group
 * without reassigning members, editing trust-group membership by hand, …). The group-manager SFC catches it
 * and surfaces the message, exactly as the structure manager surfaces StructureException — the safety rule is
 * enforced in the service, never only in the UI.
 */
final class GroupException extends RuntimeException {}
