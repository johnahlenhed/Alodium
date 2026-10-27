<?php

namespace Tests\Feature;

use App\Models\ImportRun;
use App\Models\Post;
use App\Models\User;
use App\Services\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the importer does with a post's content: WordPress shortcodes, bracketed
 * text that isn't one, and characters libxml can't read. Every loss or repair
 * must end up as a warning on the run, named by the post's title and slug.
 */
class WordPressImportContentTest extends TestCase
{
    use RefreshDatabase;

    private function wxr(string $items): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Test blog</title>
    {$items}
</channel>
</rss>
XML;
    }

    private function postItem(int $id, string $title, string $content, string $status = 'publish'): string
    {
        return <<<XML
<item>
    <title>{$title}</title>
    <content:encoded><![CDATA[{$content}]]></content:encoded>
    <wp:post_id>{$id}</wp:post_id>
    <wp:post_date_gmt>2024-05-01 08:00:00</wp:post_date_gmt>
    <wp:status>{$status}</wp:status>
    <wp:post_type>post</wp:post_type>
</item>
XML;
    }

    private function attachment(int $id, string $url, int $parent = 0, int $order = 0, string $alt = ''): string
    {
        return <<<XML
<item>
    <title>Attachment {$id}</title>
    <wp:post_id>{$id}</wp:post_id>
    <wp:post_parent>{$parent}</wp:post_parent>
    <wp:menu_order>{$order}</wp:menu_order>
    <wp:status>inherit</wp:status>
    <wp:post_type>attachment</wp:post_type>
    <wp:attachment_url><![CDATA[{$url}]]></wp:attachment_url>
    <wp:postmeta><wp:meta_key>_wp_attachment_image_alt</wp:meta_key><wp:meta_value><![CDATA[{$alt}]]></wp:meta_value></wp:postmeta>
</item>
XML;
    }

    private function import(string $xml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $xml);

        try {
            return app(WordPressImporter::class)->import($path, User::factory()->create());
        } finally {
            @unlink($path);
        }
    }

    private function content(string $title): string
    {
        return (string) Post::where('title', $title)->value('content');
    }

    /** The run's warnings for one post, as "message" strings. */
    private function warningsFor(string $title): array
    {
        $warnings = ImportRun::latest('id')->first()->report['warnings'];

        return array_values(array_map(fn ($w) => $w['message'], array_filter($warnings, fn ($w) => $w['title'] === $title)));
    }

    public function test_a_gallery_becomes_its_images_and_the_run_names_the_post(): void
    {
        $this->import($this->wxr(
            $this->attachment(11, 'https://old.example/uploads/one.jpg', alt: 'First photo').
            $this->attachment(12, 'https://old.example/uploads/two.png').
            $this->attachment(13, 'https://old.example/uploads/brochure.pdf').
            $this->postItem(1, 'Holiday', "Intro\n\n[gallery ids=\"12,11,13,99\" columns=\"2\"]\n\nOutro")
        ));

        $this->assertSame(
            // The sanitiser writes an empty alt as a bare "alt".
            "<p>Intro</p>\n<p><img src=\"https://old.example/uploads/two.png\" alt /> <img src=\"https://old.example/uploads/one.jpg\" alt=\"First photo\" /></p>\n<p>Outro</p>",
            $this->content('Holiday'),
        );

        $warning = ImportRun::latest('id')->first()->report['warnings'][0];
        $this->assertSame(['title' => 'Holiday', 'type' => 'post', 'slug' => 'holiday'], array_diff_key($warning, ['message' => 1]));
        // The PDF and the missing id 99 are not images of the export.
        $this->assertSame('A gallery was converted to 2 images in one paragraph; the gallery layout is not kept. 2 of its images are not in the export file.', $warning['message']);
    }

    public function test_a_gallery_without_ids_shows_the_images_uploaded_to_the_post_in_wordpress_order(): void
    {
        $this->import($this->wxr(
            $this->attachment(21, 'https://old.example/c.jpg', parent: 2, order: 2).
            $this->attachment(22, 'https://old.example/a.jpg', parent: 2, order: 0).
            $this->attachment(23, 'https://old.example/b.jpg', parent: 2, order: 0).
            $this->attachment(24, 'https://old.example/other-post.jpg', parent: 3).
            $this->postItem(2, 'Album', '[gallery exclude="23"] [gallery]')
        ));

        preg_match_all('/src="([^"]+)"/', $this->content('Album'), $sources);
        $this->assertSame([
            'https://old.example/a.jpg', 'https://old.example/c.jpg', // the first gallery, without 23
            'https://old.example/a.jpg', 'https://old.example/b.jpg', 'https://old.example/c.jpg', // menu_order, then id
        ], $sources[1]);
        $this->assertSame(['A gallery was converted to 3 images in one paragraph; the gallery layout is not kept.', 'A gallery was converted to 2 images in one paragraph; the gallery layout is not kept.'], array_reverse($this->warningsFor('Album')));
    }

    public function test_a_gallery_whose_images_are_missing_is_reported_not_silently_dropped(): void
    {
        $this->import($this->wxr($this->postItem(4, 'Empty gallery', "Text\n\n[gallery ids=\"98,99\"]\n\n[gallery ids=\"98\"]")));

        $this->assertSame('<p>Text</p>', $this->content('Empty gallery'));
        $this->assertSame(['A gallery was removed: none of its images are in the export file. (2 times)'], $this->warningsFor('Empty gallery'));
    }

    public function test_audio_becomes_a_link_and_the_run_names_the_post(): void
    {
        $this->import($this->wxr(
            $this->attachment(31, 'https://old.example/uploads/attached.mp3', parent: 5).
            // The closed one first: as in WordPress, an unclosed [audio] followed later by
            // [/audio] would take everything up to it as its content.
            $this->postItem(5, 'Podcast', '[audio mp3="https://old.example/x.mp3?a=1&amp;b=2"][/audio] [audio src="https://old.example/uploads/episode%201.mp3"] [audio https://old.example/positional.mp3] [audio]').
            $this->postItem(6, 'Silent', '[audio] [video]')
        ));

        $this->assertSame(
            '<p><a href="https://old.example/x.mp3?a&#61;1&amp;b&#61;2">x.mp3</a></p> '
            .'<p><a href="https://old.example/uploads/episode%201.mp3">episode 1.mp3</a></p> '
            .'<p><a href="https://old.example/positional.mp3">positional.mp3</a></p> '
            // [audio] without a file plays the first audio file uploaded to the post, as in WordPress.
            .'<p><a href="https://old.example/uploads/attached.mp3">attached.mp3</a></p>',
            $this->content('Podcast'),
        );
        $this->assertSame(['An audio player was replaced by a link to the file, which stays on the old site. (4 times)'], $this->warningsFor('Podcast'));

        $this->assertSame('', $this->content('Silent'));
        $this->assertSame([
            'An audio player was removed: no audio file was found for it.',
            'A video player was removed: no video file was found for it.',
        ], $this->warningsFor('Silent'));
    }

    public function test_the_run_page_shows_the_warnings(): void
    {
        $admin = User::factory()->create();
        $this->import($this->wxr($this->postItem(7, 'With audio', '[audio src="https://old.example/a.mp3"]')));

        $this->actingAs($admin)->get(route('import.runs.show', ImportRun::latest('id')->first()))
            ->assertInertia(fn ($page) => $page
                ->where('run.warnings.0.title', 'With audio')
                ->where('run.warnings.0.slug', 'with-audio')
                ->where('run.warnings.0.message', 'An audio player was replaced by a link to the file, which stays on the old site.'));
    }

    // From the investigation: any [word ...] used to be deleted, including prose and code.
    public function test_bracketed_text_that_is_not_a_wordpress_shortcode_is_kept(): void
    {
        $content = "Coracles [simple boat] and a quote [sic], see [1].\n\n"
            ."<pre><code>if (a[i] > 0) { \$x[key] = \$list[0]; }</code></pre>\n\n"
            .'Literal: [[gallery]] and [[caption]text[/caption]].';
        $this->import($this->wxr($this->postItem(8, 'Brackets', $content)));

        $html = $this->content('Brackets');
        $this->assertStringContainsString('<p>Coracles [simple boat] and a quote [sic], see [1].</p>', $html);
        $this->assertStringContainsString('<pre><code>if (a[i] &gt; 0) { $x[key] &#61; $list[0]; }</code></pre>', $html);
        // [[name]] is WordPress's escape for a literal shortcode.
        $this->assertStringContainsString('<p>Literal: [gallery] and [caption]text[/caption].</p>', $html);
        $this->assertSame([], $this->warningsFor('Brackets'));
    }

    public function test_unknown_shortcodes_stay_visible_and_are_reported(): void
    {
        $this->import($this->wxr($this->postItem(9, 'Plugins', '[contact-form-7 id="5" title="Contact"] [vc_row][vc_column]Text[/vc_column][/vc_row] [pullquote /]')));

        $this->assertSame('<p>[contact-form-7 id&#61;&#34;5&#34; title&#61;&#34;Contact&#34;] [vc_row][vc_column]Text[/vc_column][/vc_row] [pullquote /]</p>', $this->content('Plugins'));
        $this->assertSame(['Shortcodes that could not be converted are shown as text: [contact-form-7], [vc_column], [vc_row], [pullquote].'], $this->warningsFor('Plugins'));
    }

    public function test_captions_keep_their_text_and_alignment_and_embeds_become_links(): void
    {
        $this->import($this->wxr($this->postItem(10, 'Media', implode("\n\n", [
            '[caption id="attachment_1" align="aligncenter" width="300"]<img src="https://old.example/a.jpg" alt="A" width="300" /> The bell[/caption]',
            '[caption id="attachment_2" align="alignright" caption="Old style, with [brackets]"]<a href="https://old.example/b.jpg"><img src="https://old.example/b.jpg" alt="B" /></a>[/caption]',
            '[embed]https://www.youtube.com/watch?v=abc&amp;t=1[/embed]',
        ]))));

        $this->assertSame(
            "<p style=\"text-align: center\"><img src=\"https://old.example/a.jpg\" alt=\"A\" width=\"300\" /></p>\n"
            ."<p style=\"text-align: center\"><em>The bell</em></p>\n"
            ."<p style=\"text-align: right\"><a href=\"https://old.example/b.jpg\"><img src=\"https://old.example/b.jpg\" alt=\"B\" /></a></p>\n"
            ."<p style=\"text-align: right\"><em>Old style, with [brackets]</em></p>\n"
            .'<p><a href="https://www.youtube.com/watch?v&#61;abc&amp;t&#61;1">https://www.youtube.com/watch?v&#61;abc&amp;t&#61;1</a></p>',
            $this->content('Media'),
        );
        $this->assertSame(['Embedded media (for example a video) is shown as a link.'], $this->warningsFor('Media'));
    }

    // From the investigation: one stray byte made libxml drop the post's whole body,
    // and the post was still published, with only a file-level "damaged" warning.
    public function test_an_invalid_utf8_byte_no_longer_empties_the_post_and_the_post_is_named(): void
    {
        $results = $this->import($this->wxr(
            $this->postItem(40, 'Caf&#233; menu', "Our caf\xE9 serves coffee.\n\nSecond paragraph.").
            $this->postItem(41, 'Next post', 'Untouched.')
        ));

        $this->assertSame([], $results['errors']);
        $post = Post::where('slug', 'cafe-menu')->firstOrFail();
        $this->assertSame("<p>Our café serves coffee.</p>\n<p>Second paragraph.</p>", $post->content);
        $this->assertSame('published', $post->status);
        $this->assertSame([[
            'title' => 'Café menu', 'type' => 'post', 'slug' => 'cafe-menu',
            'message' => '1 character that was not valid UTF-8 was read as Windows-1252 (é). Check that the text looks right.',
        ]], $results['warnings']);
        $this->assertSame('<p>Untouched.</p>', $this->content('Next post'));
    }

    public function test_control_characters_no_longer_empty_the_post(): void
    {
        $results = $this->import($this->wxr($this->postItem(42, 'Pasted from Word', "Before\x0Cafter\x08, still here.")));

        $this->assertSame([], $results['errors']);
        $this->assertSame('<p>Beforeafter, still here.</p>', $this->content('Pasted from Word'));
        $this->assertSame(['2 invisible control characters that XML does not allow were removed (for example from text pasted from Word).'], $this->warningsFor('Pasted from Word'));
    }

    public function test_a_damaged_item_is_imported_as_a_draft_and_named(): void
    {
        // An unescaped "&" in the title: libxml reports it and carries on (recover mode).
        $results = $this->import($this->wxr(
            $this->postItem(43, 'Fine', 'Fine body.').
            $this->postItem(44, 'Tom & Jerry', 'Body of the damaged item.')
        ));

        $this->assertStringContainsString('The XML file is damaged or incomplete.', $results['errors'][0]);
        $this->assertSame('published', Post::where('title', 'Fine')->value('status'));

        $damaged = Post::where('content', '<p>Body of the damaged item.</p>')->firstOrFail();
        $this->assertSame('draft', $damaged->status);
        $this->assertCount(1, $results['warnings']);
        $this->assertSame($damaged->slug, $results['warnings'][0]['slug']);
        $this->assertMatchesRegularExpression('/^The export file is damaged inside this item \(line \d+: .+\), so its content may be incomplete\. It was imported as a draft/', $results['warnings'][0]['message']);
    }

    public function test_a_clean_export_has_no_warnings(): void
    {
        $results = $this->import($this->wxr($this->postItem(45, 'Plain', "Text with é, emoji 🎉 and &amp;.\n\nMore.")));

        $this->assertSame([], $results['warnings']);
        $this->assertSame([], $results['errors']);
    }
}
