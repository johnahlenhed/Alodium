<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the text import's result on the run, so the run page
 * (/import/runs/{run}) works after a refresh and for earlier imports, and
 * counts skipped images per reason for the image summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->boolean('download_images')->default(false)->after('status');
            $table->unsignedInteger('imported_posts')->default(0)->after('download_images');
            $table->unsignedInteger('imported_pages')->default(0)->after('imported_posts');
            // {skipped: [{title, type, reason}], errors: [string], found: {type: count}}
            $table->json('report')->nullable()->after('imported_pages');
            // Raw reason => count, for every skipped image (failures only keeps the first 200).
            $table->json('skipped_reasons')->nullable()->after('failures');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn(['download_images', 'imported_posts', 'imported_pages', 'report', 'skipped_reasons']);
        });
    }
};
