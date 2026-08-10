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
        Schema::table('service_accounts', function (Blueprint $table) {
            // Deferred from the Phase 3 migration until plans and routers existed.
            $table->foreignId('plan_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('router_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();

            // Provisioning state on the device the account lives on.
            $table->string('routeros_id', 32)->nullable()->after('secret');
            $table->timestamp('provisioned_at')->nullable()->after('activated_at');
            $table->text('provision_error')->nullable()->after('provisioned_at');

            $table->index(['tenant_id', 'router_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'router_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['router_id']);
            $table->dropColumn([
                'plan_id',
                'router_id',
                'routeros_id',
                'provisioned_at',
                'provision_error',
            ]);
        });
    }
};
