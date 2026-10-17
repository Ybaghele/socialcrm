<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\Plan;
use App\Models\PipelineStage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Self-serve signup: one call creates a fully usable tenant on a free trial. */
class CreateWorkspace
{
    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->id,
            ]);

            $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

            $plan = Plan::where('slug', config('omniflow.trial_plan'))->where('is_active', true)->firstOrFail();

            Subscription::create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(config('omniflow.trial_days')),
                'current_period_start' => now(),
            ]);

            Wallet::create(['workspace_id' => $workspace->id]);

            Tenancy::run($workspace, function () use ($workspace) {
                foreach (config('omniflow.default_pipeline_stages') as $i => $stage) {
                    PipelineStage::create($stage + ['position' => $i]);
                }
            });

            $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (in_array($slug, config('omniflow.reserved_slugs'), true) || Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
