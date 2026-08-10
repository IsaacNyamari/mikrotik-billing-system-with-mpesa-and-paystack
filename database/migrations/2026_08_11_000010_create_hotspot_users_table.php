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
        // One purchased HotSpot package. The matching row on the router is deleted once the
        // package is spent, but this record stays: it is what the ISP's earnings reporting
        // is built from.
        Schema::create('hotspot_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            // Set when the buyer is a known subscriber rather than a walk-in.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Username and password are the same value — the M-PESA transaction code — so the
            // customer has one thing to remember and the portal can log them in for them.
            $table->string('username', 64);
            $table->text('password');

            $table->string('source', 16)->default('purchase');
            // M-PESA receipt, or whatever else identifies where this came from.
            $table->string('reference', 64)->nullable();
            $table->string('phone', 32)->nullable();
            $table->decimal('amount', 10, 2)->default(0);

            $table->string('status', 16)->default('pending');

            $table->unsignedBigInteger('limit_uptime_seconds')->nullable();
            $table->unsignedBigInteger('limit_bytes')->nullable();

            // Wall-clock expiry, which is what the sweeper acts on.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();

            // Last usage read back off the device before the row was deleted from it.
            $table->unsignedBigInteger('used_uptime_seconds')->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();

            $table->string('routeros_id', 32)->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // A HotSpot login must be unique on the device it lives on.
            $table->unique(['router_id', 'username']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            // Drives the sweeper.
            $table->index(['status', 'expires_at']);
            $table->index(['tenant_id', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotspot_users');
    }
};
