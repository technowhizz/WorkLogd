<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JiraWorklogCreation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JiraWorklogCreation>
 */
class JiraWorklogCreationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'issue_key' => 'PROJ-'.$this->faker->numberBetween(1, 999),
            'jira_worklog_id' => (string) $this->faker->numberBetween(10000, 99999),
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}
