<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

/** Thrown by TagService when a tag operation cannot proceed (e.g. empty name). */
final class TagException extends \RuntimeException {}
