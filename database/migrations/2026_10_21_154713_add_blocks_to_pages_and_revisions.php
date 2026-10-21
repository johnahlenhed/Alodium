<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Block-based pages. blocks = null means a "legacy" page that uses `content`
 * (HTML) as before. `content` is kept unchanged on both tables.
 * Shape: { "version": 1, "blocks": [ { "id", "type", "data" } ] } (App\Blocks\BlockRegistry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('content');
        });

        Schema::table('revisions', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });

        Schema::table('revisions', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
    }
};
