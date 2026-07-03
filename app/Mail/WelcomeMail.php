<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The welcome email (onboarding-lite, NOV-123). A transactional greeting sent once on registration, on the
 * same queued T2 mail engine as {@see NotificationMail} (ShouldQueue → DB queue, drained by cron on the
 * baseline tier). The subject is code-controlled and CRLF-stripped (header-injection belt-and-braces).
 */
final class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $siteName,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to '.$this->subjectSafe($this->siteName));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome', with: [
            'name' => $this->name,
            'siteName' => $this->siteName,
            'url' => $this->url,
        ]);
    }

    private function subjectSafe(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
