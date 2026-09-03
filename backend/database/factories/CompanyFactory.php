<?php

namespace Database\Factories;

use App\Domain\Company\Models\Company;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->company(),
            'registration_no' => strtoupper(fake()->bothify('??####-#')),
            'industry' => fake()->randomElement(['Retail', 'Manufacturing', 'F&B', 'Logistics', 'Professional Services']),
            'website' => fake()->url(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
        ];
    }
}
