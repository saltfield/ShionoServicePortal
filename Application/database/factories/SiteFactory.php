<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->company().' 拠点',
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'building_name' => null,
            'phone' => fake()->numerify('0#-####-####'),
            'billing_name' => fake()->company(),
            'billing_department' => '経理部',
            'billing_postal_code' => fake()->numerify('###-####'),
            'billing_address' => fake()->address(),
            'billing_building_name' => null,
            'billing_phone' => fake()->numerify('0#-####-####'),
            'is_primary' => false,
            'is_active' => true,
        ];
    }
}
