<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use RuntimeException;

/**
 * A binding badge-management rule was violated (empty name, invalid criteria, …). The ACP badge SFC
 * catches it and surfaces the message — the safety rule is enforced in the service, never only in the UI.
 */
final class BadgeException extends RuntimeException {}
