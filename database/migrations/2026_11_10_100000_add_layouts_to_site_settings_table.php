<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the site header and footer are arranged: a value from App\Support\
 * LayoutOptions (HEADER, FOOTER). null, as on the existing row, means the
 * default ("standard"), the arrangement both had before. No column default,
 * like the other settings: the defaults live in SiteSetting::defaults().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('header_layout', 20)->nullable()->after('footer_background_color');
            $table->string('footer_layout', 20)->nullable()->after('header_layout');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['header_layout', 'footer_layout']);
        });
    }
};
