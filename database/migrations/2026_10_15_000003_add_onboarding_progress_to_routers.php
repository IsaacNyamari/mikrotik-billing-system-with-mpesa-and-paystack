<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a router is in being set up, kept for good.
     *
     * provision_fetched_at belongs to the one-time link and is cleared when the next link is
     * issued — which the setup page does on its very next render. So the one fact an operator
     * most needs after pasting, "did the router get its configuration?", was being erased
     * moments after it happened. These are never cleared by a link.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->timestamp('config_downloaded_at')->nullable()->after('provision_fetched_at');
            $table->timestamp('hotspot_ready_at')->nullable()->after('config_downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['config_downloaded_at', 'hotspot_ready_at']);
        });
    }
};
