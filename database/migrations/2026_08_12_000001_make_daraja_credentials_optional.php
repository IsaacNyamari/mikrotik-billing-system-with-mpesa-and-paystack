<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An ISP no longer has to bring a Daraja app of its own.
     *
     * The platform signs on their behalf and their till is named as the payee, so these three
     * are empty for most ISPs — they were required back when every one of them had to spend a
     * fortnight obtaining credentials from Safaricom before selling an hour of wifi.
     *
     * They stay on the table rather than being dropped: an ISP that already has its own app
     * keeps using it, and a working setup should not be taken away because a better default
     * arrived.
     */
    public function up(): void
    {
        Schema::table('mpesa_configurations', function (Blueprint $table) {
            $table->text('consumer_key')->nullable()->change();
            $table->text('consumer_secret')->nullable()->change();
            $table->text('passkey')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. Rows written since this ran legitimately hold no
        // credentials, and making the columns required again would refuse to migrate at all.
    }
};
