<?php

namespace Database\Factories;

use App\Domain\Company\Models\Company;
use App\Domain\Service\Models\ServiceSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceSubscription> */
class ServiceSubscriptionFactory extends Factory
{
    protected $model = ServiceSubscription::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'owner_user_id' => User::factory(),
            'name' => 'Annual server hosting', 'type' => 'server',
            'customer_email' => 'customer@example.test', 'internal_email' => 'support@example.test',
            'amount' => '1200.00', 'currency' => 'MYR', 'renewal_months' => 12,
            'expires_on' => '2026-06-30', 'cycle_id' => (string) Str::uuid(), 'status' => 'active',
        ];
    }
}
