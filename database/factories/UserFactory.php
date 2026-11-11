<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\PermissionProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'permission_profile_id' => fn () => PermissionProfile::default()?->id,
            'active' => true,
        ];
    }

    /** One of the starting profiles: employee, dispatcher, supervisor or admin. */
    public function role(string $role): static
    {
        $name = ['employee' => 'Team Member', 'dispatcher' => 'Dispatcher', 'supervisor' => 'Supervisor', 'admin' => 'Admin'][$role];

        return $this->state(fn () => ['permission_profile_id' => PermissionProfile::where('name', $name)->value('id')]);
    }
}
