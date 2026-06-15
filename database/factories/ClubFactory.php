<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Club;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    protected $model = Club::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'tagline' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'privacy' => 'public',
            'is_listed' => true,
            'member_count' => 0,
        ];
    }

    public function public(): static
    {
        return $this->state(['privacy' => 'public', 'is_listed' => true]);
    }

    public function closed(): static
    {
        return $this->state(['privacy' => 'closed', 'is_listed' => true]);
    }

    public function private(): static
    {
        return $this->state(['privacy' => 'private', 'is_listed' => true]);
    }

    /** A private, unlisted club — the "private-hidden" fence case (M1.5). */
    public function hidden(): static
    {
        return $this->state(['privacy' => 'private', 'is_listed' => false]);
    }
}
