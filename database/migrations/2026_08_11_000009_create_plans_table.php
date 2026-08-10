<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type', 16);
            $table->string('name');
            // Doubles as the RouterOS profile name, so it is restricted to characters
            // RouterOS accepts in an identifier.
            $table->string('code', 64);
            $table->string('description')->nullable();

            $table->decimal('price', 10, 2)->default(0);

            // How long the package lasts once the customer starts using it.
            $table->unsignedInteger('validity_amount');
            $table->string('validity_unit', 16);

            // Null means uncapped.
            $table->unsignedInteger('download_kbps')->nullable();
            $table->unsignedInteger('upload_kbps')->nullable();
            $table->unsignedBigInteger('data_cap_mb')->nullable();

            $table->unsignedSmallInteger('shared_users')->default(1);
            $table->unsignedInteger('idle_timeout_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        // What each plan looks like on each router. A plan is a business object; a profile is
        // the copy of it living on a device, and the two drift whenever a push fails or an
        // operator edits the router by hand.
        Schema::create('plan_router', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();

            $table->string('profile_name', 64);
            // RouterOS internal id, e.g. "*A". Only meaningful on that device.
            $table->string('routeros_id', 32)->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'router_id']);
            $table->index(['tenant_id', 'router_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_router');
        Schema::dropIfExists('plans');
    }
};
