<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hand-rolled `media` table from Sprint 1 is replaced by Spatie Media Library,
 * which needs the same table name. It was never used (no model code read or wrote it),
 * so it is dropped here and Spatie's table is created by the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('media');
    }

    public function down(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size'); // bytes
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }
};
