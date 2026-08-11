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
        // How one ISP's captive portal looks and reads. Every column is nullable: a portal
        // must render for an ISP that has configured nothing, so the model falls back to
        // sensible defaults rather than the page depending on a row existing.
        Schema::create('portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('brand_name')->nullable();
            $table->string('logo_path')->nullable();

            $table->string('headline')->nullable();
            $table->string('intro_text')->nullable();
            $table->string('success_text')->nullable();
            $table->string('footer_text')->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('terms_url')->nullable();

            // Stored as #rrggbb. Validated on the way in so it can be written straight into a
            // stylesheet without escaping games.
            $table->string('primary_color', 7)->nullable();
            $table->string('color_scheme', 8)->default('auto');

            $table->boolean('show_speed')->default(true);
            $table->boolean('show_data_cap')->default(true);

            $table->text('custom_css')->nullable();

            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_settings');
    }
};
