<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Badge>
 */
class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' badge';

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => fake()->sentence(),
            'criteria' => ['type' => 'post_count', 'threshold' => 5],
            'icon_token' => null,
            'color_token' => 'blue',
            'is_active' => true,
        ];
    }

    /** @param array<string,mixed> $criteria */
    public function criteria(array $criteria): static
    {
        return $this->state(fn () => ['criteria' => $criteria]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
