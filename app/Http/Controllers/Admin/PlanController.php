<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET   /admin/plans        the catalogue and how many tenants sit on each plan
 * PATCH /admin/plans/{plan} edit price / quota / availability
 *
 * Plans are platform data (not tenant data) and both the landing page and the
 * Billing screen read them live, so an edit here is visible to tenants
 * immediately — that is the point, but it is why this is super-admin only.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        $subscribers = Subscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->selectRaw('plan_id, count(*) as total')
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id');

        $plans = Plan::query()
            ->orderBy('price_paise')
            ->get()
            ->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'pricePaise' => (int) $plan->price_paise,
                'gstPercent' => (int) $plan->gst_percent,
                'monthlyActiveContacts' => (int) $plan->monthly_active_contacts,
                'overageBlockSize' => (int) $plan->overage_block_size,
                'overagePricePaise' => (int) $plan->overage_price_paise,
                'maxInstagramAccounts' => (int) $plan->max_instagram_accounts,
                'maxAgentSeats' => (int) $plan->max_agent_seats,
                'isActive' => (bool) $plan->is_active,
                'subscribers' => (int) ($subscribers[$plan->id] ?? 0),
            ])
            ->all();

        return Inertia::render('Admin/Plans', ['plans' => $plans]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'], // rupees
            'monthly_active_contacts' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_agent_seats' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_instagram_accounts' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', Rule::in([true, false, 1, 0, '1', '0'])],
        ]);

        $plan->update([
            'name' => $data['name'],
            'price_paise' => (int) $data['price'] * 100,
            'monthly_active_contacts' => (int) $data['monthly_active_contacts'],
            'max_agent_seats' => (int) $data['max_agent_seats'],
            'max_instagram_accounts' => (int) $data['max_instagram_accounts'],
            'is_active' => filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN),
        ]);

        return back()->with('success', "{$plan->name} updated.");
    }
}
