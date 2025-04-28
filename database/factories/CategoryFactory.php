<?php

namespace Database\Factories;

use App\Models\Tenants\Category;
use App\Models\LandlordUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'user_id' => LandlordUser::factory(),
        ];
    }
}
