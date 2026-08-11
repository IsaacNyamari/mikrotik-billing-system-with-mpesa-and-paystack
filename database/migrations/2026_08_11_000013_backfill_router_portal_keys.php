<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // portal_key was added as a nullable column, so routers that already existed have
        // none — and a router without one has no captive portal URL at all. The model's
        // creating hook only covers new rows, so the existing ones are filled in here.
        DB::table('routers')
            ->whereNull('portal_key')
            ->orderBy('id')
            ->each(function (object $router): void {
                DB::table('routers')
                    ->where('id', $router->id)
                    ->update(['portal_key' => Str::lower(Str::random(24))]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately irreversible: clearing these would break portal URLs that customers
        // and routers may already be using.
    }
};
