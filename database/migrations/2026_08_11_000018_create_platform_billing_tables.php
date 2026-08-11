<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the platform charges each ISP, and whether they have paid it.
     *
     * Deliberately separate from `payments`, which is an ISP collecting from its own
     * customers and is what feeds their earnings reports. Mixing the platform's income into
     * that table would put the platform's fees into every ISP's revenue figures.
     *
     * These tables carry `tenant_id` but are **not** tenant-scoped models: an ISP being
     * locked out for non-payment must still be able to read its own bill, and the platform
     * has to be able to see every ISP's at once.
     */
    public function up(): void
    {
        // One per ISP: the terms they signed up under and where they stand.
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default('pending');

            // Copied from config at sign-up rather than read from it at billing time, so an
            // ISP is always billed the terms they agreed to.
            $table->decimal('onboarding_fee', 10, 2);
            $table->timestamp('onboarding_paid_at')->nullable();

            // Between 3% and 5%, per ISP. A large operator can be discounted without a
            // deployment, and the bounds are enforced by the model.
            $table->decimal('hotspot_commission_rate', 5, 2);
            $table->decimal('pppoe_monthly_fee', 10, 2);

            // The month currently being accrued. Invoices are cut for the month just ended.
            $table->date('period_started_on')->nullable();
            $table->date('last_invoiced_on')->nullable();

            // Set when an ISP is let in before paying — a trial, or terms agreed offline.
            $table->timestamp('access_granted_until')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique('tenant_id');
            $table->index('status');
        });

        Schema::create('tenant_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Unique platform-wide and quoted by the ISP when they pay, so it must not be
            // guessable from a sequence that reveals how many ISPs exist.
            $table->string('number', 32)->unique();

            $table->string('status', 16)->default('pending');
            $table->string('kind', 16)->default('usage');

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->decimal('onboarding_amount', 10, 2)->default(0);
            $table->decimal('hotspot_amount', 10, 2)->default(0);
            $table->decimal('pppoe_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            // How the total was arrived at: what was collected, at what rate, over how many
            // lines. An ISP disputing a bill is owed the arithmetic, not just the answer.
            $table->json('breakdown')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('due_at');
        });

        // The ISP paying the platform. Mirrors the shape of `payments` because it is the same
        // Daraja conversation, and for the same reason it is safe to settle twice.
        Schema::create('tenant_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 16)->default('mpesa');
            $table->string('status', 16)->default('pending');

            $table->string('phone', 20);
            $table->decimal('amount', 10, 2);

            $table->string('checkout_request_id')->nullable()->index();
            $table->string('merchant_request_id')->nullable();
            $table->string('receipt')->nullable();

            $table->integer('result_code')->nullable();
            $table->string('result_description')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('callback_payload')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payments');
        Schema::dropIfExists('tenant_invoices');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
