<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('source_channel')->nullable();  // first channel they came from
            $table->json('source_details')->nullable();    // ad id, post id, campaign id
            $table->json('custom_fields')->nullable();     // cart_value, last_order_id...
            $table->boolean('is_opted_in')->default(true); // WhatsApp marketing consent
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable(); // "Last Touch"
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'pipeline_stage_id']);
            $table->index(['workspace_id', 'last_interaction_at']);
            $table->index(['workspace_id', 'assigned_user_id']);
        });

        // One person, many channels: WhatsApp number + IG handle + FB PSID → one contact.
        Schema::create('contact_channel_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('channel');                 // denormalised for fast filtering
            $table->string('external_user_id');        // wa_id / IGSID / PSID
            $table->string('handle')->nullable();      // @ananya_lifestyle, fb/meera.nambiar
            $table->timestamp('last_inbound_at')->nullable(); // 24-hour messaging window
            $table->timestamps();

            $table->unique(['channel_account_id', 'external_user_id']);
            $table->index('contact_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');                    // stored without '#'
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('applied_by')->nullable();  // user | automation | keyword_rule
            $table->timestamps();

            $table->primary(['contact_id', 'tag_id']);
        });

        // Saved audiences ("Subscribers with Cart Value > ₹2,000").
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');                   // {"all":[{"field":"tag","op":"in","value":[...]}]}
            $table->unsignedInteger('cached_count')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contact_channel_identities');
        Schema::dropIfExists('contacts');
    }
};
