<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The header's padding above and below its content, in px (Settings). Nullable
 * with no default, like logo_height: null, on the row that existed before this
 * column, is SiteSetting's default (12px, what the header used before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('header_padding')->nullable()->after('header_layout');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('header_padding');
        });
    }
};
