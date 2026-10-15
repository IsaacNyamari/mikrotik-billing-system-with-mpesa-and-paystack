<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a PPPoE subscriber owes their ISP, one row per billing period. Separate from
        // tenant_invoices, which is what the ISP owes the platform.
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            // INV-000001, per ISP, never reissued.
            $table->string('number', 32);

            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('KES');

            // pending, paid, overdue, void (App\Enums\InvoiceStatus).
            $table->string('status', 16)->default('pending');

            // The date the money is owed: the end of the paid period it renews. One invoice per
            // line per due date, so the billing run can raise it as often as it likes.
            $table->dateTime('due_at');
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();

            $table->dateTime('paid_at')->nullable();
            $table->string('receipt', 64)->nullable();
            $table->string('void_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['service_account_id', 'due_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoices');
    }
};
