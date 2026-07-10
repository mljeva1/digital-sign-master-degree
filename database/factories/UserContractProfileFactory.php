<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserContractProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserContractProfile>
 */
class UserContractProfileFactory extends Factory
{
    protected $model = UserContractProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'oib' => $this->syntheticOib(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'postal_code' => (string) fake()->numerify('#####'),
            'country_code' => 'HR',
            'phone' => (string) fake()->numerify('09########'),
        ];
    }

    /**
     * A profile missing the fields required for contract-party autofill.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'oib' => null,
            'address_line1' => null,
            'postal_code' => null,
            'city' => null,
        ]);
    }

    /**
     * An 11-digit synthetic identifier for tests only.
     * This is NOT a checksum-valid Croatian OIB.
     */
    private function syntheticOib(): string
    {
        return (string) fake()->numerify('###########');
    }
}
