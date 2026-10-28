<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public site's main navigation as its own list (Settings page): each entry
 * links to a page or to a URL (a path on the site such as /blog, or an http(s)
 * address), with its own label and position. Replaces pages.show_in_nav,
 * pages.nav_order and site_settings.show_blog_in_nav / show_contact_in_nav,
 * which are copied in here and then left unused (dropped by a later migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table) {
            $table->id();
            // Null for a page entry: the page's current title is shown. A URL entry always has one.
            $table->string('label', 60)->nullable();
            // Exactly one of page_id and url is set (UpdateSiteSettingsRequest).
            $table->foreignId('page_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('url', 2048)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });

        $this->backfill();
    }

    public function down(): void
    {
        // The old columns were never changed, so the previous navigation is still there.
        Schema::dropIfExists('navigation_items');
    }

    /**
     * Today's navigation, in today's order: the flagged pages by nav_order, then
     * id (as PublicSiteSettings sorted them), then Blog and Contact if switched on.
     * Labels stay null, so the pages keep showing their titles. Pages that are
     * flagged but not on the public site are copied too: they were hidden only
     * while unpublished, and stay so. The query builder, not the models, so this
     * keeps working when the models change.
     */
    private function backfill(): void
    {
        $now = now();
        $entries = DB::table('pages')
            ->where('show_in_nav', true)
            ->orderBy('nav_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $id) => ['label' => null, 'page_id' => $id, 'url' => null])
            ->all();

        // No saved settings row means the defaults, which have both links on (SiteSetting::defaults()).
        $settings = DB::table('site_settings')->where('id', 1)->first();
        if ($settings === null || $settings->show_blog_in_nav) {
            $entries[] = ['label' => 'Blog', 'page_id' => null, 'url' => '/blog'];
        }
        if ($settings === null || $settings->show_contact_in_nav) {
            $entries[] = ['label' => 'Contact', 'page_id' => null, 'url' => '/contact'];
        }

        foreach ($entries as $index => $entry) {
            DB::table('navigation_items')->insert([...$entry, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
};
