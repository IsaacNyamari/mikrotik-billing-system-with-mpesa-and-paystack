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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('customer_number', 32);
            $table->string('type', 16)->default('individual');
            $table->string('name');
            $table->string('company_name')->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 32);
            $table->string('alternate_phone', 32)->nullable();
            $table->string('national_id', 64)->nullable();

            $table->string('address')->nullable();
            $table->string('city', 128)->nullable();

            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Customer numbers and phone numbers are unique inside one ISP, not across the
            // platform: two ISPs may legitimately serve the same person.
            $table->unique(['tenant_id', 'customer_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
