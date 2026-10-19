<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public marketing page is reachable by guests and reads its pricing from
 * the same `plans` table the billing screen uses.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_landing_page(): void
    {
        Plan::create([
            'name' => 'Starter Plan', 'slug' => 'starter', 'price_paise' => 199900,
            'monthly_active_contacts' => 500, 'max_instagram_accounts' => 1, 'max_agent_seats' => 2,
        ]);
        Plan::create([
            'name' => 'Growth Plan', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);
        // Inactive plans must not leak onto the marketing page.
        Plan::create([
            'name' => 'Legacy Plan', 'slug' => 'legacy', 'price_paise' => 9999,
            'monthly_active_contacts' => 10, 'max_instagram_accounts' => 1, 'max_agent_seats' => 1,
            'is_active' => false,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->where('canLogin', true)
                ->where('canRegister', true)
                ->has('plans', 2)
                ->where('plans.0.slug', 'starter')
                ->where('plans.0.priceRupees', 1999)
                ->where('plans.0.contacts', 500)
                ->where('plans.1.slug', 'growth')
                ->where('plans.1.priceRupees', 4999)
                ->where('plans.1.instagram', 5)
                ->where('plans.1.seats', 5));
    }

    public function test_the_landing_page_renders_without_any_plans(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->has('plans', 0));
    }
}
