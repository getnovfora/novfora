<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

/**
 * ACP v3 · v3-a (ADR-0080) — an Admin Manager rule was violated: an unknown/unassignable admin section. The
 * escalation fence itself (only a full admin may grant an Administration-tier key, never beyond your own ceiling)
 * is enforced by RoleManager::assertWithinCeiling, which throws RoleException; this covers the bundle-specific
 * input rules. The Admin Manager SFC catches both and surfaces the message — the rules live in the service.
 */
final class AdminBundleException extends \RuntimeException {}
