<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Reaction;
use App\Models\ReputationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReputationEvent>
 */
class ReputationEventFactory extends Factory
{
    protected $model = ReputationEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_type' => (new Reaction)->getMorphClass(),
            'source_id' => Reaction::factory(),
            'points' => 1,
            'created_at' => now(),
        ];
    }
}
