<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use RuntimeException;

/** A delegation was refused (not a co-owner, a non-delegable key, exceeds the ceiling, a bad window, …). */
final class DelegationException extends RuntimeException {}
