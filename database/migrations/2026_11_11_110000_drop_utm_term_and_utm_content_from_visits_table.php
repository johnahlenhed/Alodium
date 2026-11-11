<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data minimisation (privacy by design): utm_term and utm_content are no longer
 * collected, and the values already stored are deleted with their columns.
 * They were never shown or used, and they are the two UTM parameters where
 * email and ad tools most often put subscriber-level identifiers. Only
 * utm_source, utm_medium and utm_campaign are kept (App\Support\UtmValue).
 *
 * down() brings the empty columns back; the deleted values can't be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['utm_term', 'utm_content']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('utm_content')->nullable()->after('utm_campaign');
            $table->string('utm_term')->nullable()->after('utm_content');
        });
    }
};
