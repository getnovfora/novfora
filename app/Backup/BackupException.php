<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

/** A backup or restore operation could not complete. The message is safe to surface (no secrets). */
final class BackupException extends RuntimeException {}
