<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the packages themselves are laid out and worded.
     *
     * The portal already carried an ISP's colours and copy; this is the shape of the page. A
     * HotSpot's front page is the whole of what most of its customers ever see of the ISP, and
     * two ISPs on the same platform should not be recognisably the same product.
     */
    public function up(): void
    {
        Schema::table('portal_settings', function (Blueprint $table) {
            // 'grid' puts packages in tiles, two to a row on a phone; 'list' keeps the older
            // stacked rows, which suit an ISP selling only two or three packages.
            $table->string('layout', 8)->default('grid')->after('color_scheme');

            // 'tinted' tiles carry the brand colour; 'plain' ones are white with a brand
            // button, for an ISP whose colour is too strong to sit behind text.
            $table->string('card_style', 8)->default('tinted')->after('layout');

            // The second colour, for badges and the banner. Falls back to the brand colour,
            // so an ISP that sets nothing still gets a coherent page.
            $table->string('accent_color', 7)->nullable()->after('primary_color');

            $table->boolean('show_banner')->default(true)->after('card_style');
            $table->string('banner_text')->nullable()->after('show_banner');

            // What the button on each package says, and what money is called. "Ksh" reads as
            // local where "KES" reads as a bank statement.
            $table->string('buy_button_text', 40)->nullable()->after('banner_text');
            $table->string('currency_label', 8)->nullable()->after('buy_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('portal_settings', function (Blueprint $table) {
            $table->dropColumn([
                'layout',
                'card_style',
                'accent_color',
                'show_banner',
                'banner_text',
                'buy_button_text',
                'currency_label',
            ]);
        });
    }
};
