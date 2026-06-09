<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Digest;

use RuntimeException;

/**
 * Internal control signal: thrown inside the assembler transaction when a claim turns up zero items (a
 * concurrent tick grabbed them first). It forces the transaction to roll back so the period is NOT consumed
 * by an empty run — a later tick can still assemble it once items exist. Never escapes the assembler.
 */
final class DigestNothingToSend extends RuntimeException {}
