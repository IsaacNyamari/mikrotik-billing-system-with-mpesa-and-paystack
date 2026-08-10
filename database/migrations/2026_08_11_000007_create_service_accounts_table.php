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
        // A service account is the actual network identity. One customer may hold several, so
        // this table — not customers — is what plans, subscriptions and RADIUS hang off.
        //
        // plan_id arrives with Phase 4 and router_id with Phase 8; both are added by their own
        // migrations rather than being stubbed out here.
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('account_number', 32);
            $table->string('type', 16);
            $table->string('label')->nullable();

            $table->string('username', 128);
            $table->text('secret')->nullable();

            $table->string('status', 16)->default('pending');

            $table->string('static_ip', 45)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('installation_address')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The network login is unique within one ISP. Global uniqueness is a RADIUS
            // concern and is handled by the realm-qualified identity in Phase 7, so that two
            // ISPs can both have a subscriber called "john" without colliding.
            $table->unique(['tenant_id', 'username']);
            $table->unique(['tenant_id', 'account_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
    }
};
