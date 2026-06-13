<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

use RuntimeException;

/**
 * Raised for any module/plugin lifecycle or manifest failure (invalid manifest, failed compatibility or
 * dependency check, a refused permission collision, a lifecycle precondition). The message is operator-facing
 * and safe to surface in the ACP.
 */
final class ModuleException extends RuntimeException {}
