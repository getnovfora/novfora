<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    protected $model = Reaction::class;

    public function definition(): array
    {
        $types = array_keys((array) config('hearth.reactions.types', ['like' => []]));

        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement($types ?: ['like']),
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
