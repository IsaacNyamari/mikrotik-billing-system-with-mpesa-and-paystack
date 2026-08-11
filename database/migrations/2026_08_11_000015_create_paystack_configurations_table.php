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
        // Kept apart from mpesa_configurations rather than folded into one table with a JSON
        // blob: the two providers need genuinely different fields, and typed columns can be
        // validated and encrypted individually.
        Schema::create('paystack_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->text('secret_key');
            $table->string('public_key')->nullable();

            $table->string('currency', 3)->default('KES');
            // Paystack requires an email for every charge. A walk-in has only given a phone
            // number, so one is synthesised against this domain.
            $table->string('receipt_domain')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        // Only one provider may be live at a time, so activating one has to stand down the
        // other. Recording the choice explicitly beats inferring it from two is_active flags
        // that can disagree.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('payment_provider', 16)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('payment_provider');
        });

        Schema::dropIfExists('paystack_configurations');
    }
};
