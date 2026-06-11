<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Account;

/**
 * Raised when an account deletion is refused by an invariant the caller is expected to surface gracefully
 * (today: the sole-administrator guard). Distinct from the HTTP 403 the admin-forced authorisation guards
 * abort with — this one the confirm UIs catch and render as a blocking message, no deletion performed.
 */
final class AccountDeletionException extends \RuntimeException {}
