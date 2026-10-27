<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per WordPress import: progress and results of the image downloads
 * that run on the queue after the text import (App\Jobs\DownloadContentImages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // importing, downloading, done, failed
            $table->string('status', 20);
            // Posts and pages with images to download, and how many of them are finished.
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_done')->default(0);
            $table->unsignedInteger('images_found')->default(0);
            $table->unsignedInteger('images_downloaded')->default(0);
            $table->unsignedInteger('images_reused')->default(0);
            $table->unsignedInteger('images_skipped')->default(0);
            // For the max_total_bytes limit.
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            // At most 200 entries of {item, url, reason}.
            $table->json('failures')->nullable();
            // Hosts images may be downloaded from: the exported site's own.
            $table->json('allowed_hosts')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
