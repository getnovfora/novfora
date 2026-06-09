<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Mail;

use App\Deliverability\Unsubscribe;
use App\Deliverability\Verp;
use App\Models\DigestQueueItem;
use App\Models\User;
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
        $site = (string) config('app.name', 'Hearth');

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
        return new Content(view: 'mail.digest', with: [
            'items' => $this->items,
            'unsubscribeUrl' => Unsubscribe::urlFor($this->user),
        ]);
    }
}
