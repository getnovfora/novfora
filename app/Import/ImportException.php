<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/** Raised for an importer failure (unreachable source, unknown driver). Operator-facing message. */
final class ImportException extends RuntimeException {}
