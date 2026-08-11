<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the operator chose when the setup script was built.
     *
     * Without this, an operator who names `wlan1` when adding a router comes back to the
     * script page and finds `ether3` — the default — because nothing remembered the answer.
     * They then paste a script that bridges the wrong interface.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->json('setup_options')->nullable()->after('wg_configured_at');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('setup_options');
        });
    }
};
