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
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 64);

            // Connection details. The host is whatever address the platform can reach the
            // router on — a LAN address today, a WireGuard address once Phase 9 lands.
            $table->string('host');
            $table->unsignedSmallInteger('api_port')->default(8728);
            $table->string('api_username');
            $table->text('api_password');
            $table->boolean('uses_tls')->default(false);

            $table->string('status', 16)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Filled in by the health probe, never by hand.
            $table->string('identity')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number', 64)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedTinyInteger('cpu_load')->nullable();
            $table->unsignedBigInteger('free_memory_bytes')->nullable();
            $table->unsignedBigInteger('total_memory_bytes')->nullable();

            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
