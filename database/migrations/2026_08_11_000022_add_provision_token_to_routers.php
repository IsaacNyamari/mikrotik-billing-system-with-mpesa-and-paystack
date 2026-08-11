<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The token a router quotes to fetch its own configuration.
     *
     * A reset reboots the device, which discards whatever the operator was pasting. RouterOS
     * answers that with `run-after-reset`, which needs the configuration to already be a file
     * on the device — so the router fetches it from here first.
     *
     * That fetch happens before the tunnel exists, so it is necessarily a public endpoint. It
     * is guarded the way the specification requires of any provisioning token: unguessable,
     * short-lived, and single-use.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('provision_token', 64)->nullable()->unique()->after('setup_options');
            $table->timestamp('provision_token_expires_at')->nullable()->after('provision_token');
            $table->timestamp('provision_fetched_at')->nullable()->after('provision_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['provision_token', 'provision_token_expires_at', 'provision_fetched_at']);
        });
    }
};
