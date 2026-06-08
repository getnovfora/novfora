<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use RuntimeException;

/**
 * A guard-rail violation in the forum structure manager (ACP v1, PART 2) — e.g. deleting a node with
 * content but no chosen destination, reparenting a node into its own subtree, or giving a category a
 * parent. The message is operator-facing (surfaced in the panel), never a stack trace.
 */
class StructureException extends RuntimeException {}
