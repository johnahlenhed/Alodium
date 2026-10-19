<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * publish_at: when the editor wants the item to go live (scheduled, in the future).
 * published_at: when it actually went live. Cleared/set by content:publish-scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->after('published_at');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('publish_at');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('publish_at');
        });
    }
};
