<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Alodium has no categories or tags (docs/ARCHITECTURE.md, Known gaps): an import
 * says how many of the imported posts' assignments it dropped, in the run's notes.
 */
class ImportTaxonomyNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $id = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    /** A category or tag reference, as WordPress exports it on an item. */
    private function term(string $domain, string $slug, string $name): string
    {
        return "<category domain=\"{$domain}\" nicename=\"{$slug}\"><![CDATA[{$name}]]></category>";
    }

    private function item(string $title, string $terms, string $type = 'post', string $status = 'publish'): string
    {
        $id = $this->id++;

        return "<item><title><![CDATA[{$title}]]></title><content:encoded><![CDATA[<p>Text</p>]]></content:encoded>"
            ."<wp:post_id>{$id}</wp:post_id><wp:post_date_gmt><![CDATA[2024-05-01 08:00:00]]></wp:post_date_gmt>"
            ."<wp:status><![CDATA[{$status}]]></wp:status><wp:post_type><![CDATA[{$type}]]></wp:post_type>{$terms}</item>";
    }

    private function import(string ...$items): array
    {
        // The categories are listed in the channel too, as WordPress exports them; they aren't counted.
        $xml = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title><wp:wxr_version>1.2</wp:wxr_version>'
            .'<wp:category><wp:term_id>2</wp:term_id><wp:category_nicename><![CDATA[reviews]]></wp:category_nicename><wp:category_parent><![CDATA[]]></wp:category_parent><wp:cat_name><![CDATA[Reviews]]></wp:cat_name></wp:category>'
            .implode('', $items).'</channel></rss>';
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $xml);

        try {
            return app(WordPressImporter::class)->import($path, $this->admin);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_dropped_category_and_tag_assignments_of_imported_posts_are_counted(): void
    {
        $results = $this->import(
            $this->item('Review one', $this->term('category', 'reviews', 'Reviews').$this->term('category', 'news', 'News').$this->term('post_tag', 'php', 'PHP')),
            $this->item('Review two', $this->term('category', 'reviews', 'Reviews').$this->term('post_tag', 'php', 'PHP').$this->term('post_tag', 'laravel', 'Laravel')),
            // Not counted: WordPress's default category, other taxonomies, a skipped post, a page.
            $this->item('Plain', $this->term('category', 'uncategorized', 'Uncategorized').$this->term('post_format', 'post-format-gallery', 'Gallery')),
            $this->item('Private', $this->term('category', 'reviews', 'Reviews'), status: 'private'),
            $this->item('About', $this->term('category', 'reviews', 'Reviews'), type: 'page'),
        );

        $note = "3 category assignments and 3 tag assignments on the imported posts were not imported: Alodium doesn't support categories or tags. The posts themselves were imported without them.";
        $this->assertSame([$note], $results['notes']);
        $this->assertSame(3, Post::count(), 'The posts are imported as before.');
        $this->assertSame([], $results['errors']);

        // Stored with the run and shown on its page.
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page->where('run.notes', [$note]));
    }

    public function test_singular_and_one_kind_only(): void
    {
        $this->assertSame(
            ["1 category assignment on the imported posts was not imported: Alodium doesn't support categories. The posts themselves were imported without them."],
            $this->import($this->item('One', $this->term('category', 'reviews', 'Reviews')))['notes'],
        );
        $this->assertSame(
            ["2 tag assignments on the imported posts were not imported: Alodium doesn't support tags. The posts themselves were imported without them."],
            $this->import($this->item('Two', $this->term('post_tag', 'a', 'A').$this->term('post_tag', 'b', 'B')))['notes'],
        );
    }

    public function test_there_is_no_note_without_categories_or_tags(): void
    {
        $results = $this->import(
            $this->item('Plain', ''),
            $this->item('Default only', $this->term('category', 'uncategorized', 'Uncategorized')),
        );

        $this->assertSame([], $results['notes']);
        $this->actingAs($this->admin)->get("/import/runs/{$results['run_id']}")->assertInertia(fn (Assert $page) => $page->where('run.notes', []));
    }
}
