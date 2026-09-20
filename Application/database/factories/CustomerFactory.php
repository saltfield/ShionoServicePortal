<?php

namespace Database\Factories;

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\BusinessPartner;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'TMPC'.fake()->unique()->numerify('######'),
            'managing_bp_id' => BusinessPartner::factory(),
            'name' => fake()->company(),
            'name_kana' => null,
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'building_name' => null,
            'phone' => fake()->numerify('0#-####-####'),
            'email' => fake()->unique()->safeEmail(),
            'entity_type' => EntityType::Corporate,
            'two_factor_mode' => TwoFactorMode::Optional,
            'is_active' => true,
        ];
    }

    public function withIssuedCode(): static
    {
        return $this->state(fn () => [
            'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn),
        ]);
    }
}
