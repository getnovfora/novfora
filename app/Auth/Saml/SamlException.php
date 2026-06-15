<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Saml;

use RuntimeException;

/** A SAML operation failed (not configured, invalid response signature, unknown subject). */
class SamlException extends RuntimeException {}
