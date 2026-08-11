<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Someone asking about the platform from the public site.
     *
     * Stored rather than only emailed: mail is the part of any deployment most likely to be
     * quietly broken, and an ISP who filled in a form and heard nothing is a customer lost to
     * a misconfigured relay.
     *
     * Not tenant-owned — an enquiry arrives before there is any ISP to own it.
     */
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('company')->nullable();
            $table->string('subject', 120)->nullable();
            $table->text('message');

            // Roughly how big they are, so a first reply can be useful rather than generic.
            $table->string('router_count', 32)->nullable();

            $table->timestamp('handled_at')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index('handled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
