<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row (id 1) with the public site's identity and look. The columns have no
 * defaults on purpose: the defaults live in SiteSetting::defaults() only, and
 * the row is always written with every value (see SiteSettingsController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 80);
            $table->string('site_description', 300)->nullable();
            $table->string('footer_text', 200)->nullable();
            $table->boolean('show_site_name');
            $table->char('brand_color', 7);
            $table->string('font_body', 20);
            $table->string('font_heading', 20);
            $table->string('corners', 20);
            $table->string('buttons', 20);
            $table->boolean('show_blog_in_nav');
            $table->boolean('show_contact_in_nav');
            // Deleting the page falls back to "latest posts" instead of deleting the settings.
            $table->foreignId('front_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
