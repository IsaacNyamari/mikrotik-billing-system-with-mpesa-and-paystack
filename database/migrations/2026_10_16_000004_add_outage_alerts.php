<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            // Who at the ISP is texted when a router goes down and comes back. Comma-separated;
            // empty means the ISP's contact phone.
            $table->string('alert_phones', 120)->nullable()->after('remind_days_before');
            $table->boolean('alert_outages')->default(true)->after('alert_phones');
        });

        Schema::table('router_outages', function (Blueprint $table) {
            // Set once the ISP has been texted that this router is down. The recovery text only
            // follows one that was sent, so a one-minute blip texts nobody.
            $table->dateTime('alerted_at')->nullable()->after('compensated_at');
        });
    }

    public function down(): void
    {
        Schema::table('router_outages', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });

        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropColumn(['alert_phones', 'alert_outages']);
        });
    }
};
