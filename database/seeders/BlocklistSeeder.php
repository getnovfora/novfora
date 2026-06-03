<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BlocklistEntry;
use Illuminate\Database\Seeder;

/**
 * Seeds the local disposable / throwaway email-domain list (ADR-0007 §2.2). Always-available baseline
 * signal (no external dependency); never expires. The crowdsourced StopForumSpam cache, by contrast, is
 * warmed at lookup time and TTL'd. Idempotent.
 */
class BlocklistSeeder extends Seeder
{
    /** @return list<string> a small starter set; operators extend it in the ACP */
    public static function disposableDomains(): array
    {
        return [
            'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org',
            'trashmail.com', 'yopmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com',
            'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com', 'mailnesia.com',
        ];
    }

    public function run(): void
    {
        foreach (self::disposableDomains() as $domain) {
            BlocklistEntry::updateOrCreate(
                ['type' => 'email_domain', 'value' => $domain, 'source' => 'disposable'],
                ['confidence' => 100, 'expires_at' => null],
            );
        }
    }
}
