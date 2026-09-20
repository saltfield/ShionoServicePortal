<?php

namespace Database\Factories;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\BusinessPartner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<BusinessPartner>
 */
class BusinessPartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'TMP'.fake()->unique()->numerify('######'),
            'name' => fake()->company(),
            'parent_id' => null,
            'depth' => 1,
            'two_factor_mode' => TwoFactorMode::Optional,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BusinessPartner $partner): void {
            //
        })->afterCreating(function (BusinessPartner $partner): void {
            if ($partner->parent_id === null) {
                // Ensure closure self-row exists when created via factory attributes.
                $exists = DB::table('bp_closure')
                    ->where('ancestor_id', $partner->id)
                    ->where('descendant_id', $partner->id)
                    ->exists();

                if (! $exists) {
                    DB::table('bp_closure')->insert([
                        'ancestor_id' => $partner->id,
                        'descendant_id' => $partner->id,
                        'depth_diff' => 0,
                    ]);
                }
            }
        });
    }

    public function withIssuedCode(): static
    {
        return $this->state(fn () => [
            'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn),
        ]);
    }
}
