<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Router alerts are now opt-in, and switched off for everyone who had them by default.
     *
     * Production, 2026-10-15/16: fourteen "outages" in a day and a half, most a single missed
     * check over the tunnel with the router fine, each texting the ISP twice. An alert that
     * cries wolf every few hours is one people learn to ignore, which is worse than none.
     */
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('alert_outages')->default(false)->change();
        });

        DB::table('sms_settings')->update(['alert_outages' => false]);
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('alert_outages')->default(true)->change();
        });
    }
};
