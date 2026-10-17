<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('status')->default('active');       // platform-level switch: active | suspended
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->string('custom_domain')->nullable()->unique(); // future: crm.customer.com
            $table->json('limits_override')->nullable();       // per-tenant enterprise limits
            $table->timestamp('onboarded_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false); // platform staff, not a tenant role
            $table->foreignId('current_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('agent');
            $table->string('token', 64)->unique();             // sha256 of the emailed token
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
            $table->dropColumn('is_super_admin');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['status', 'suspended_at', 'suspension_reason', 'custom_domain', 'limits_override', 'onboarded_at']);
        });
    }
};
