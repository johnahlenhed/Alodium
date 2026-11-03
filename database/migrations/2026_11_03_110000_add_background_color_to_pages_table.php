<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page's own background colour (#RRGGBB), or null for none: the site's page
 * colour, as every page had before. Checked by App\Rules\PageBackgroundColor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->char('background_color', 7)->nullable()->after('og_image');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('background_color');
        });
    }
};
