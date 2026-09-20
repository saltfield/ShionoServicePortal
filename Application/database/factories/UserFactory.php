<?php

namespace Database\Factories;

use App\Domains\Auth\Enums\UserType;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'login_id' => strtoupper(fake()->unique()->bothify('USER####')),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => UserType::Admin,
            'bp_id' => null,
            'customer_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'user_type' => UserType::Admin,
            'bp_id' => null,
            'customer_id' => null,
        ]);
    }

    public function bp(?BusinessPartner $partner = null): static
    {
        return $this->state(function () use ($partner) {
            $partner ??= BusinessPartner::factory()->create();

            return [
                'user_type' => UserType::Bp,
                'bp_id' => $partner->id,
                'customer_id' => null,
            ];
        });
    }

    public function customer(?Customer $customer = null): static
    {
        return $this->state(function () use ($customer) {
            $customer ??= Customer::factory()->create();

            return [
                'user_type' => UserType::Customer,
                'bp_id' => null,
                'customer_id' => $customer->id,
            ];
        });
    }
}
