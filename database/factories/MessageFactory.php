<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        $body = fake()->sentence();

        // Default to the markdown canonical shape ({"source": "..."}) — the lightest valid body the
        // ContentRenderer markdown path accepts. Service-written messages render their own HTML/text;
        // the factory pre-fills the caches so model-only tests have renderable rows.
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'body_format' => 'markdown',
            'body_canonical' => ['source' => $body],
            'body_html_cache' => '<p>'.e($body).'</p>',
            'body_text' => $body,
            'approved_state' => 'approved',
        ];
    }
}
