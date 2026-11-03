<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The height the public site shows the logo at, in px (Settings). Nullable with
 * no default, like the other columns' defaults: null, on the row that existed
 * before this column, is SiteSetting's default (36px, what the header used before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('logo_height')->nullable()->after('show_site_name');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('logo_height');
        });
    }
};
