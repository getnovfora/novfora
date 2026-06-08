<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Bounce;

/**
 * Spike P2 — a normalised bounce/complaint event from any ingestion path (webhook / DSN / ARF / VERP). The
 * suppression decision lives here so every path is consistent: a COMPLAINT always suppresses; a BOUNCE
 * suppresses only when PERMANENT (SMTP 5.x.x). A transient 4.x.x bounce (mailbox-full / greylist) is parsed
 * but NEVER suppressed — it self-heals, and suppressing it would silence a deliverable address.
 */
final readonly class BounceEvent
{
    public const BOUNCE = 'bounce';

    public const COMPLAINT = 'complaint';

    public function __construct(
        public string $email,
        public string $type,        // bounce | complaint
        public bool $permanent,     // for a bounce: 5.x.x permanent vs 4.x.x transient
    ) {}

    public static function complaint(string $email): self
    {
        return new self($email, self::COMPLAINT, true);
    }

    public static function bounce(string $email, bool $permanent): self
    {
        return new self($email, self::BOUNCE, $permanent);
    }

    /** Only a complaint or a PERMANENT bounce warrants suppression. */
    public function shouldSuppress(): bool
    {
        return $this->type === self::COMPLAINT || ($this->type === self::BOUNCE && $this->permanent);
    }

    /** The email_suppressions.reason value for this event (bounce | complaint). */
    public function reason(): string
    {
        return $this->type === self::COMPLAINT ? self::COMPLAINT : self::BOUNCE;
    }
}
