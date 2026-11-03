<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site footer's background colour (#RRGGBB), or null for none: the light
 * grey surface, as the footer had before. Checked by App\Rules\FooterBackgroundColor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->char('footer_background_color', 7)->nullable()->after('nav_background_color');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('footer_background_color');
        });
    }
};
