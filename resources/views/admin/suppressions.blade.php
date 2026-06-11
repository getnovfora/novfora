{{-- SPDX-License-Identifier: Apache-2.0 --}}
@extends('layouts.app', ['title' => 'System · Email suppressions'])

@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[
        ['label' => 'Admin'],
        ['label' => 'System'],
        ['label' => 'Email suppressions'],
    ]" />
@endsection

@section('content')
    <x-admin.shell title="Email suppressions">
        <p class="text-sm text-ink-muted max-w-2xl">
            Addresses that hard-bounced or filed a spam complaint are suppressed automatically (via a provider
            webhook, a polled bounce mailbox, or a signed VERP return path) and are skipped on future sends to
            protect your domain's sending reputation. You can also suppress or un-suppress an address by hand
            here — the always-available baseline floor, working even with no email provider configured.
        </p>

        {{-- Operator email-setup checklist (spike-p2-memo §5; mirrors `php artisan novfora:mail:test`). Sending
             to strangers — verification mail, digests — is where shared-host SMTP burns reputation. --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-ink">Email deliverability checklist</h2>
            <ol class="mt-3 space-y-2 text-sm text-ink-muted list-decimal pl-5 max-w-2xl">
                <li>
                    Your <strong>From</strong> address must be on <strong>your sending domain</strong>. An
                    off-domain From fails SPF/DKIM alignment and lands in spam (or is rejected). VERP only
                    rewrites the envelope sender / Return-Path — the on-domain From stays aligned.
                </li>
                <li>Publish <strong>SPF</strong>, <strong>DKIM</strong> and <strong>DMARC</strong> DNS records for that domain.</li>
                <li>
                    For mail to strangers, baseline shared-host SMTP is best-effort. The single highest-value
                    upgrade is a transactional provider
                    (<strong>Postmark / SES / Mailgun / Resend</strong>) with its bounce webhook configured.
                </li>
            </ol>
            <p class="mt-3 text-xs text-ink-subtle">
                Verify outbound mail end-to-end with <code class="rounded bg-surface-sunken px-1 py-0.5">php artisan novfora:mail:test you@example.com</code>.
            </p>
        </x-ui.card>

        <livewire:admin.suppressions />

        {{-- Non-VERP bounce manual-review queue (spike-p2-memo §2b / §8). --}}
        <livewire:admin.bounce-reviews />
    </x-admin.shell>
@endsection
