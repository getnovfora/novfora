<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Mail;

use App\Theme\Sandbox\TemplateService;
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
        // Subject values are user-influenced (topic title, actor username). The subject is a code-controlled
        // string (NEVER admin-rendered) + Symfony QP-encodes any CRLF; this explicit strip is belt-and-braces
        // against header injection (T2 apex fence).
        $title = $this->subjectSafe($this->payload['topic_title'] ?? 'your forum activity');
        $actor = $this->subjectSafe($this->actor);

        return new Envelope(subject: match ($this->event) {
            'reply' => "New reply in “{$title}”",
            'mention' => "{$actor} mentioned you",
            'reaction' => "{$actor} reacted to your post",
            'pm.received' => "{$actor} sent you a message",
            'follow' => "{$actor} started following you",
            'moderation' => 'A moderation notice',
            default => 'Notification',
        });
    }

    public function content(): Content
    {
        // T2 (ADR-0099): the BODY is admin-customisable through the sandbox (variables auto-escaped, no PHP/Blade,
        // scripts lint-blocked). '' = no enabled custom template → the Blade default in the view is used.
        $customBody = app(TemplateService::class)->render('email.notification', [
            'event' => $this->event,
            'actor' => $this->actor,
            'topic_title' => (string) ($this->payload['topic_title'] ?? ''),
            'url' => (string) ($this->payload['url'] ?? ''),
        ]);

        return new Content(view: 'mail.notification', with: ['customBody' => $customBody]);
    }

    private function subjectSafe(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
