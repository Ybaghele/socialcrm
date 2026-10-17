<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): flushed after every queued job and every Octane request.
        // Optional now: TenantContext::current() registers this itself on first use.
        TenantContext::current();
    }
}
