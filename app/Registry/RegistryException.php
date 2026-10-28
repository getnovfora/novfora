<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Registry;

use RuntimeException;

/**
 * An operator-facing registry failure (bad feed signature, rollback, tampered package, revoked publisher,
 * downgrade, unreachable). Carries only safe, human-readable messages — never a stack trace or internal path.
 */
final class RegistryException extends RuntimeException {}
