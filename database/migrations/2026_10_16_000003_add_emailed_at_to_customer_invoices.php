<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            // When the invoice (or, once paid, its receipt) was last emailed to the subscriber,
            // so the ISP can see it went and the billing run does not send it twice.
            $table->dateTime('emailed_at')->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('emailed_at');
        });
    }
};
