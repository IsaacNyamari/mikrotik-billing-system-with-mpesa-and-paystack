<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outages, and what each one cost the people who had paid for time.
     *
     * A HotSpot package is sold by the clock: an hour bought at two ends at three whether or
     * not the router had power at half past. So when a router goes down, the time its
     * customers lose is recorded against the outage and given back to them.
     *
     * The record stays after the time has been credited. It is what an ISP shows a customer who
     * asks what happened, and what tells them which sites keep losing power.
     */
    public function up(): void
    {
        Schema::create('router_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();

            // The last moment the platform knew the router was up, so the earliest it can have
            // failed. Customers are credited from here: an outage measured generously costs
            // the ISP a minute, measured stingily it costs them a customer.
            $table->dateTime('started_at');
            $table->dateTime('detected_at');
            $table->timestamp('ended_at')->nullable();

            // When the device itself came back up, read from its uptime. Null when it never
            // went down: the link failed but the router kept running.
            $table->timestamp('device_booted_at')->nullable();

            $table->string('cause', 32)->nullable();
            $table->string('last_error')->nullable();

            $table->unsignedInteger('affected_users')->default(0);
            $table->unsignedBigInteger('credited_seconds')->default(0);
            $table->unsignedInteger('relogged_users')->default(0);
            $table->timestamp('compensated_at')->nullable();

            $table->timestamps();

            $table->index(['router_id', 'ended_at']);
            $table->index(['tenant_id', 'started_at']);
        });

        Schema::create('outage_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotspot_user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('credited_seconds');

            // Whether this customer was online when the router went down, and so whether the
            // platform tries to log them back in once it returns.
            $table->boolean('was_online')->default(false);
            $table->string('relogin_status', 16)->nullable();
            $table->unsignedSmallInteger('relogin_attempts')->default(0);
            $table->timestamp('relogged_in_at')->nullable();
            $table->string('relogin_error')->nullable();

            $table->timestamps();

            // Credited once per outage, however many times the watcher sees the recovery.
            $table->unique(['router_outage_id', 'hotspot_user_id']);
        });

        Schema::table('hotspot_users', function (Blueprint $table) {
            // Read off /ip/hotspot/active every minute. After a power cut the router has
            // forgotten every session, and this is how the platform knows whose device to log
            // back in.
            $table->string('last_mac_address', 17)->nullable()->after('used_bytes');
            $table->string('last_ip_address', 45)->nullable()->after('last_mac_address');
            $table->timestamp('last_online_at')->nullable()->after('last_ip_address');
            $table->unsignedInteger('compensated_seconds')->default(0)->after('last_online_at');
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->dropColumn(['last_mac_address', 'last_ip_address', 'last_online_at', 'compensated_seconds']);
        });

        Schema::dropIfExists('outage_compensations');
        Schema::dropIfExists('router_outages');
    }
};
