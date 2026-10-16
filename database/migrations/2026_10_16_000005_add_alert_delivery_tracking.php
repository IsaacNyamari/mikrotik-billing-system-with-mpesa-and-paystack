<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            // Router alerts are retried until one attempt per number is accepted. The key names
            // the alert ("outage:12:down"), so every attempt at it can be found and counted.
            $table->foreignId('router_id')->nullable()->after('service_account_id')->constrained()->nullOnDelete();
            $table->string('alert_key', 64)->nullable()->after('kind');
            $table->index(['router_id', 'alert_key']);
        });

        Schema::table('routers', function (Blueprint $table) {
            // Set while the ISP has been told the router answers but refuses to be managed.
            $table->dateTime('error_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('error_alerted_at');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['router_id', 'alert_key']);
            $table->dropConstrainedForeignId('router_id');
            $table->dropColumn('alert_key');
        });
    }
};
