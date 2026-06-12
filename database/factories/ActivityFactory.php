<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Activity> */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'verb' => Activity::VERB_TOPIC_CREATED,
            'subject_type' => (new Topic)->getMorphClass(),
            'subject_id' => fake()->numberBetween(1, 1000),
            'object_type' => null,
            'object_id' => null,
            'scope_forum_id' => null,
            'created_at' => now(),
        ];
    }

    public function verb(string $verb): static
    {
        return $this->state(['verb' => $verb]);
    }

    /** Point the activity at a real subject model (sets subject_type + subject_id together). */
    public function forSubject(Model $subject): static
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function inForum(?int $forumId): static
    {
        return $this->state(['scope_forum_id' => $forumId]);
    }

    public function by(?int $actorId): static
    {
        return $this->state(['actor_id' => $actorId]);
    }
}
