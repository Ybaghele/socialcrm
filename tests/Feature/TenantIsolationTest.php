<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\CrossTenantWriteException;
use App\Support\Tenancy\MissingTenantException;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $slug): Workspace
    {
        return Workspace::create(['name' => $slug, 'slug' => $slug, 'owner_id' => User::factory()->create()->id]);
    }

    public function test_reads_only_see_the_current_workspace(): void
    {
        [$a, $b] = [$this->workspace('acme'), $this->workspace('globex')];

        Tenancy::run($a, fn () => Contact::create(['name' => 'A lead']));
        Tenancy::run($b, fn () => Contact::create(['name' => 'B lead']));

        Tenancy::run($a, fn () => $this->assertSame(['A lead'], Contact::pluck('name')->all()));
        Tenancy::run($b, fn () => $this->assertSame(['B lead'], Contact::pluck('name')->all()));
    }

    public function test_another_tenants_record_id_is_not_found(): void
    {
        [$a, $b] = [$this->workspace('acme'), $this->workspace('globex')];
        $bContact = Tenancy::run($b, fn () => Contact::create(['name' => 'B lead']));

        Tenancy::run($a, fn () => $this->assertNull(Contact::find($bContact->id)));
    }

    public function test_queries_with_no_workspace_fail_closed(): void
    {
        $this->expectException(MissingTenantException::class);

        Contact::count();
    }

    public function test_cannot_write_into_a_different_workspace(): void
    {
        [$a, $b] = [$this->workspace('acme'), $this->workspace('globex')];

        $this->expectException(CrossTenantWriteException::class);

        Tenancy::run($a, fn () => Contact::create(['name' => 'sneaky', 'workspace_id' => $b->id]));
    }

    public function test_context_is_restored_after_run(): void
    {
        $a = $this->workspace('acme');

        Tenancy::run($a, fn () => null);

        $this->assertNull(Tenancy::id());
    }

    public function test_facade_and_model_scope_share_the_same_context(): void
    {
        $a = $this->workspace('acme');

        Tenancy::run($a, function () use ($a) {
            $this->assertSame($a->id, TenantContext::current()->id());
            $this->assertSame(TenantContext::current(), app(TenantContext::class));
            $this->assertSame($a->id, Contact::create(['name' => 'x'])->workspace_id);
        });
    }
}
