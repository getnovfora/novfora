<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'last_read_at' => null,
            'left_at' => null,
            'can_invite' => false,
        ];
    }

    public function canInvite(): static
    {
        return $this->state(fn () => ['can_invite' => true]);
    }

    public function left(): static
    {
        return $this->state(fn () => ['left_at' => now()]);
    }
}
