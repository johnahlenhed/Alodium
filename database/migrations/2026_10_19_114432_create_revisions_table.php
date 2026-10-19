<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots of published posts and pages (see App\Traits\HasRevisions).
 * Polymorphic, so one table serves both models.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            // model_type (App\Models\Post / App\Models\Page) + model_id, with an index.
            $table->morphs('model');
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
