<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRelationship>
 */
class UserRelationshipFactory extends Factory
{
    protected $model = UserRelationship::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'related_user_id' => User::factory(),
            'type' => UserRelationship::TYPE_IGNORE,
        ];
    }

    public function ignore(): static
    {
        return $this->state(fn () => ['type' => UserRelationship::TYPE_IGNORE]);
    }

    public function follow(): static
    {
        return $this->state(fn () => ['type' => UserRelationship::TYPE_FOLLOW]);
    }
}
