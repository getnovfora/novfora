<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

/** A vote was rejected by PollService integrity rules (closed poll, invalid option, choice-count violation). */
final class PollVoteException extends \RuntimeException {}
