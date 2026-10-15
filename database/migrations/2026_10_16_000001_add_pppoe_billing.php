<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_accounts', function (Blueprint $table) {
            // Paid up to here. A PPPoE line is cut when this passes and comes back the moment a
            // payment moves it forward again.
            $table->dateTime('expires_at')->nullable()->after('terminated_at');

            // The unguessable part of the link a subscriber is texted, so they can pay without
            // an account of their own.
            $table->string('pay_token', 40)->nullable()->unique()->after('expires_at');

            // The expiry date each reminder was last sent for. A new cycle has a new date, so
            // each reminder goes once per cycle however often the scheduler runs.
            $table->date('reminded_before_for')->nullable()->after('pay_token');
            $table->date('reminded_due_for')->nullable()->after('reminded_before_for');

            // Only a line the system cut for non-payment is reconnected by a payment. One the
            // ISP suspended by hand stays suspended until they say otherwise.
            $table->boolean('suspended_for_nonpayment')->default(false)->after('reminded_due_for');

            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('service_account_id')->nullable()->after('hotspot_user_id')->constrained()->nullOnDelete();

            // When a renewal was applied to its line. Null on a paid renewal means it still
            // has to be, which is what the billing run retries.
            $table->dateTime('applied_at')->nullable()->after('failed_at');
        });

        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(true);

            // HostPinnacle credentials. Secrets are encrypted at rest.
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('api_key')->nullable();
            $table->string('sender_id', 11)->nullable();

            $table->unsignedTinyInteger('remind_days_before')->default(3);

            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 32);
            $table->string('kind', 32);
            $table->text('body');

            // sent, failed, or skipped (nothing to send it with).
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->json('response')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_settings');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_account_id');
            $table->dropColumn('applied_at');
        });

        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'expires_at']);
            $table->dropColumn(['expires_at', 'pay_token', 'reminded_before_for', 'reminded_due_for', 'suspended_for_nonpayment']);
        });
    }
};
