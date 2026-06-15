<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use RuntimeException;

/** A club-membership operation was refused (guard violated, invite invalid, sole-owner, rank ceiling). */
class ClubMembershipException extends RuntimeException {}
