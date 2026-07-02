<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules\Packaging;

use RuntimeException;

/**
 * A refusal from the install-from-zip pipeline (U17, ADR-0104): a hostile/malformed archive, a failed or
 * missing signature, an untrusted key, or a policy rejection. Its message is operator-facing (surfaced as an
 * inline ACP error, never a 500) and must not leak filesystem internals.
 */
final class PackageException extends RuntimeException {}
