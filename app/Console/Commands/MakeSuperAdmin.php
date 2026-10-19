<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flips the platform-staff flag that gates the /admin console.
 *
 * `is_super_admin` is intentionally not mass-assignable, so it cannot be set
 * through a registration form or an API field — only from the shell:
 *
 *   php artisan omniflow:make-super-admin you@example.com
 *   php artisan omniflow:make-super-admin you@example.com --revoke
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'omniflow:make-super-admin
                            {email : The user to promote or demote}
                            {--revoke : Remove platform-staff access instead of granting it}';

    protected $description = 'Grant (or revoke) super-admin access to the platform console';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $revoke = (bool) $this->option('revoke');
        $grant = ! $revoke;

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with the email {$email}.");

            return self::FAILURE;
        }

        if ((bool) $user->is_super_admin === $grant) {
            $this->warn($grant
                ? "{$user->email} is already a super admin."
                : "{$user->email} is not a super admin.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $grant) {
            $user->forceFill(['is_super_admin' => $grant])->save();
        });

        $user->refresh();

        $this->info($grant
            ? "{$user->name} <{$user->email}> can now reach /admin."
            : "{$user->name} <{$user->email}> can no longer reach /admin.");

        return self::SUCCESS;
    }
}
