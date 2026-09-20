<?php

namespace Database\Factories;

use App\Domains\Catalog\Enums\BillingType;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('#######'),
            'name' => fake()->words(3, true),
            'description' => null,
            'billing_type' => BillingType::Running,
            'required_item_id' => null,
            'partition_price' => 1000,
            'recommended_price' => 2000,
            'user_price' => 3000,
            'tax_rate' => 10,
            'is_active' => true,
        ];
    }

    public function initial(): static
    {
        return $this->state(fn () => ['billing_type' => BillingType::Initial]);
    }

    public function running(): static
    {
        return $this->state(fn () => ['billing_type' => BillingType::Running]);
    }
}
