<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // "growth" matches your billing screen (₹4,999, 2,500 leads, 5 IG, 5 seats, ₹1,500 per 500 overage).
        // "starter" and "scale" are PLACEHOLDER numbers: set your real pricing.
        $plans = [
            ['slug' => 'starter', 'name' => 'Starter Plan', 'price_paise' => 199900, 'monthly_active_contacts' => 500,
             'max_instagram_accounts' => 1, 'max_agent_seats' => 2, 'overage_price_paise' => 150000],
            ['slug' => 'growth', 'name' => 'Growth Plan', 'price_paise' => 499900, 'monthly_active_contacts' => 2500,
             'max_instagram_accounts' => 5, 'max_agent_seats' => 5, 'overage_price_paise' => 150000],
            ['slug' => 'scale', 'name' => 'Scale Plan', 'price_paise' => 1499900, 'monthly_active_contacts' => 10000,
             'max_instagram_accounts' => 15, 'max_agent_seats' => 20, 'overage_price_paise' => 120000],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['gst_percent' => 18, 'overage_block_size' => 500, 'is_active' => true]);
        }
    }
}
