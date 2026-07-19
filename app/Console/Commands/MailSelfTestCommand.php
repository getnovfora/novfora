<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\TestMailer;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:mail:test {email}` — deliverability self-test (ADR-0014). Sends one message through the
 * configured transport and prints the SPF/DKIM/DMARC + best-effort-baseline guidance the admin panel echoes.
 * The send itself routes through the shared TestMailer (U16 / NOV-114) — the single self-test sender.
 */
class MailSelfTestCommand extends Command
{
    protected $signature = 'novfora:mail:test {email : Address to send the self-test to}';

    protected $description = 'Send a deliverability self-test email and print deliverability guidance.';

    public function handle(TestMailer $mailer): int
    {
        $email = (string) $this->argument('email');

        try {
            $mailer->send($email);
            $this->info("Self-test email dispatched to {$email}.");
        } catch (\Throwable $e) {
            $this->error('Self-test send failed: '.class_basename($e));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Deliverability checklist (ADR-0014):');
        $this->line('  • SPF, DKIM and DMARC DNS records aligned with your From domain.');
        $this->line('  • Baseline shared-host SMTP is BEST-EFFORT — for verification email, a transactional');
        $this->line('    provider (Postmark/SES/Mailgun) is the single highest-value enhanced upgrade.');

        return self::SUCCESS;
    }
}
