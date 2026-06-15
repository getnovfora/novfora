<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

use RuntimeException;

/** A payment provider could not start/complete an operation (disabled, unconfigured, unsupported). */
class PaymentException extends RuntimeException {}
