<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Social;

use RuntimeException;

/** An OAuth sign-in/link was refused (provider disabled, no email, email collision, identity already linked). */
class SocialAuthException extends RuntimeException {}
