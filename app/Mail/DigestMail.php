<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Mail;

use App\Deliverability\Unsubscribe;
use App\Deliverability\Verp;
use App\Models\DigestQueueItem;
use App\Models\User;
use App\Theme\Sandbox\TemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Spike P2 — the coalesced digest email. NOT ShouldQueue: it is sent synchronously inside {@see SendDigestJob}
 * (the job is the queued, idempotent unit), so there is no second queue hop. Carries a 1-click unsubscribe
 * (RFC 8058 List-Unsubscribe / List-Unsubscribe-Post) and a signed VERP Return-Path so a bounce identifies
 * this recipient on the always-available floor. The on-domain `From` is untouched (config mail.from) for
 * SPF/DKIM alignment — VERP only rewrites the envelope sender.
 */
final class DigestMail extends Mailable
{
    use SerializesModels;

    /** @param  list<DigestQueueItem>  $items */
    public function __construct(
        public int $runId,
        public User $user,
        public array $items,
    ) {
        // VERP signed Return-Path (envelope sender), independent of the on-domain From. No-op when VERP off.
        $this->withSymfonyMessage(function (Email $message): void {
            $verp = app(Verp::class)->returnPathFor((int) $this->user->getKey(), $this->runId);
            if ($verp !== null) {
                $message->returnPath($verp);
            }
        });
    }

    public function envelope(): Envelope
    {
        $count = count($this->items);
        $site = (string) config('app.name', 'NovFora');

        return new Envelope(subject: "Your {$site} digest — {$count} ".Str::plural('update', $count));
    }

    public function headers(): Headers
    {
        $url = Unsubscribe::urlFor($this->user);

        // RFC 8058 one-click: the URL accepts POST (List-Unsubscribe-Post), with the human GET as fallback.
        return new Headers(text: [
            'List-Unsubscribe' => "<{$url}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $unsubscribeUrl = Unsubscribe::urlFor($this->user);

        // T2 (ADR-0099): the BODY is admin-customisable through the sandbox. Items are flattened to plain arrays
        // (the sandbox does array-key access only) so a custom template can {% for item in items %}; every value
        // is auto-escaped on output. '' = no enabled custom template → the Blade default in the view is used.
        $customBody = app(TemplateService::class)->render('email.digest', [
            'recipient_name' => (string) ($this->user->display_name ?? $this->user->username ?? ''),
            'unsubscribe_url' => $unsubscribeUrl,
            'items' => array_map(fn (DigestQueueItem $i): array => [
                'actor' => (string) ($i->actor_username ?? 'Someone'),
                'topic_title' => (string) ($i->payload['topic_title'] ?? ''),
                'url' => (string) ($i->payload['url'] ?? ''),
                'event' => (string) $i->event_type,
            ], $this->items),
        ]);

        return new Content(view: 'mail.digest', with: [
            'items' => $this->items,
            'unsubscribeUrl' => $unsubscribeUrl,
            'customBody' => $customBody,
        ]);
    }
}
