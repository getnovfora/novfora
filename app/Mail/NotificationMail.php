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
 * Email notification (ADR-0014). ShouldQueue → enqueued to the DB queue and drained by cron on the baseline
 * tier (ADR-0011), so it is correct within one cron interval and never blocks the request.
 */
final class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array{topic_title?:string, url?:string} $payload */
    public function __construct(
        public string $event,
        public string $actor,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->payload['topic_title'] ?? 'your forum activity';

        return new Envelope(subject: match ($this->event) {
            'reply' => "New reply in “{$title}”",
            'mention' => "{$this->actor} mentioned you",
            'reaction' => "{$this->actor} reacted to your post",
            'pm.received' => "{$this->actor} sent you a message",
            'follow' => "{$this->actor} started following you",
            'moderation' => 'A moderation notice',
            default => 'Notification',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification');
    }
}
