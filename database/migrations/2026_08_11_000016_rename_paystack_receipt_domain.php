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
        // The column asked for a domain, which is not what anyone types into a field about
        // email. A full address is the natural answer and now the expected one, so the name
        // says what it holds.
        Schema::table('paystack_configurations', function (Blueprint $table) {
            $table->renameColumn('receipt_domain', 'billing_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paystack_configurations', function (Blueprint $table) {
            $table->renameColumn('billing_email', 'receipt_domain');
        });
    }
};
