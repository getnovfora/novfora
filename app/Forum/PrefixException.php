<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use RuntimeException;

/**
 * A binding prefix-management rule was violated (empty label, invalid state, …). The ACP prefix SFC
 * catches it and surfaces the message — the safety rule is enforced in the service, never only in the UI.
 */
final class PrefixException extends RuntimeException {}
