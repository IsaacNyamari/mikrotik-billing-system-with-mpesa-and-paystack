<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two secrets a wiped router is rebuilt with.
     *
     * A reset puts `admin` back on the factory password — blank on older boards — so the
     * rebuild disables it and creates an administrator of the ISP's own with a generated
     * password. That password is the way into the device when the platform cannot reach it,
     * so it has to be kept, and kept encrypted.
     *
     * An uplink that is a PPPoE line needs its upstream credentials written into the router
     * on every rebuild. They belong to the ISP's own provider and are as sensitive as anything
     * else here, so they are not left in `setup_options` in cleartext.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('admin_username', 64)->nullable()->after('api_password');
            $table->text('admin_password')->nullable()->after('admin_username');
            $table->text('wan_pppoe_password')->nullable()->after('admin_password');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['admin_username', 'admin_password', 'wan_pppoe_password']);
        });
    }
};
