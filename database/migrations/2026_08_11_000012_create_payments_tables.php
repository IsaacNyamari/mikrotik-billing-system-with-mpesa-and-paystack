<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Each ISP collects into its own M-PESA till, so credentials are per tenant.
        Schema::create('mpesa_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('environment', 16)->default('sandbox');
            $table->string('short_code', 16);
            $table->string('party_b', 16)->nullable();
            $table->string('transaction_type', 32)->default('CustomerPayBillOnline');

            $table->text('consumer_key');
            $table->text('consumer_secret');
            $table->text('passkey');

            // Daraja does not sign its callbacks, so the only thing distinguishing a genuine
            // delivery is this unguessable value in the URL we hand Safaricom.
            $table->string('callback_token', 64)->unique();

            $table->string('account_reference', 32)->default('INTERNET');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Filled once the payment has bought something.
            $table->foreignId('hotspot_user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 16)->default('mpesa');
            $table->string('status', 16)->default('pending');

            $table->string('phone', 32);
            $table->decimal('amount', 10, 2);

            // Safaricom's handles for the request. CheckoutRequestID is what a callback
            // arrives quoting, so it has to be unique and indexed.
            $table->string('checkout_request_id', 64)->nullable()->unique();
            $table->string('merchant_request_id', 64)->nullable();

            // The receipt becomes the customer's username and password, and is the natural
            // idempotency key for a repeated callback.
            $table->string('receipt', 64)->nullable();

            $table->integer('result_code')->nullable();
            $table->string('result_description')->nullable();

            $table->json('request_payload')->nullable();
            $table->json('callback_payload')->nullable();

            // Captured from the HotSpot redirect so the portal can log the buyer in.
            $table->string('client_mac', 32)->nullable();
            $table->string('client_ip', 45)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'receipt']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::table('routers', function (Blueprint $table) {
            // Identifies the router in public captive-portal URLs without exposing the ISP's
            // name or a guessable id.
            $table->string('portal_key', 32)->nullable()->unique()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('portal_key');
        });

        Schema::dropIfExists('payments');
        Schema::dropIfExists('mpesa_configurations');
    }
};
