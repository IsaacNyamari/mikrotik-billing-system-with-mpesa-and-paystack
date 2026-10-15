<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the platform collects what ISPs owe it — one row for the whole installation.
     *
     * These used to live only in the environment, which meant switching collection on was a
     * job for whoever could edit a file on the server, and an ISP looking at an unpayable bill
     * was shown the name of an environment variable. Kept here, the platform's owner sets
     * them from the dashboard.
     *
     * Deliberately not tenant-owned and not in `mpesa_configurations`: these collect *from*
     * ISPs, and no ISP may ever read them. Every secret is encrypted at rest.
     */
    public function up(): void
    {
        Schema::create('platform_payment_settings', function (Blueprint $table) {
            $table->id();

            $table->string('preferred_provider', 16)->nullable();

            $table->boolean('mpesa_enabled')->default(false);
            $table->string('mpesa_environment', 16)->default('production');
            $table->string('mpesa_short_code', 32)->nullable();
            $table->string('mpesa_party_b', 32)->nullable();
            $table->string('mpesa_transaction_type', 40)->default('CustomerPayBillOnline');
            $table->text('mpesa_consumer_key')->nullable();
            $table->text('mpesa_consumer_secret')->nullable();
            $table->text('mpesa_passkey')->nullable();
            $table->string('mpesa_account_reference', 40)->nullable();
            $table->string('mpesa_callback_token', 64)->nullable()->unique();

            $table->boolean('paystack_enabled')->default(false);
            $table->text('paystack_secret_key')->nullable();
            $table->string('paystack_public_key')->nullable();
            $table->string('paystack_currency', 8)->default('KES');
            $table->string('paystack_billing_email')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_settings');
    }
};
