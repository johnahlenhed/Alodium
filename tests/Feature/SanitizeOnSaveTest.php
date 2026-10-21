<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SanitizeOnSaveTest extends TestCase
{
    use RefreshDatabase;

    private const DANGEROUS = '<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src="/a.jpg" onerror="alert(2)">';

    /** What TipTap's toolbar produces; all of it must survive a save unchanged. */
    private const TIPTAP = '<h2 style="text-align: center">Heading</h2><h3>Sub</h3>'
        .'<p style="text-align: right"><strong>b</strong> <em>i</em> <s>s</s> <u>u</u> <code>code</code></p>'
        .'<pre><code>block</code></pre><hr /><ul><li><p>one</p></li></ul><ol><li><p>two</p></li></ol>'
        .'<blockquote><p>quote</p></blockquote>'
        .'<p style="text-align: left"><a target="_blank" rel="noopener noreferrer" href="https://example.com">link</a></p>'
        .'<img src="https://example.com/a.jpg" alt="A" />';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public static function kinds(): array
    {
        return ['posts' => [Post::class, 'posts'], 'pages' => [Page::class, 'pages']];
    }

    private function assertClean(?string $html): void
    {
        foreach (['onclick', 'onerror', '<script', 'alert(', 'javascript:'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, (string) $html);
        }
    }

    #[DataProvider('kinds')]
    public function test_dangerous_html_is_removed_on_create_and_update(string $model, string $route): void
    {
        $this->actingAs($this->admin)->post("/{$route}", ['title' => 'Item', 'status' => 'draft', 'content' => self::DANGEROUS])
            ->assertSessionHasNoErrors();
        $item = $model::firstOrFail();
        $this->assertClean($item->content);
        $this->assertStringContainsString('<p>Hi</p>', $item->content);

        $this->actingAs($this->admin)->put("/{$route}/{$item->id}", ['title' => 'Item', 'status' => 'draft', 'content' => self::DANGEROUS.'<p>Edited</p>'])
            ->assertSessionHasNoErrors();
        $this->assertClean($item->fresh()->content);
        $this->assertStringContainsString('<p>Edited</p>', $item->fresh()->content);
    }

    #[DataProvider('kinds')]
    public function test_tiptap_output_survives_a_save_unchanged(string $model, string $route): void
    {
        $this->actingAs($this->admin)->post("/{$route}", ['title' => 'Item', 'status' => 'draft', 'content' => self::TIPTAP]);

        $this->assertSame(self::TIPTAP, $model::firstOrFail()->content);
    }

    public function test_page_update_without_content_leaves_content_alone(): void
    {
        $page = Page::create(['author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft', 'content' => '<p>kept</p>']);

        $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => 'Renamed', 'status' => 'draft'])->assertSessionHasNoErrors();

        $this->assertSame('<p>kept</p>', $page->fresh()->content);
    }

    public function test_blocks_are_untouched_when_content_is_sanitised(): void
    {
        $blocks = ['version' => 1, 'blocks' => [
            ['id' => 'h', 'type' => 'hero', 'data' => ['heading' => 'Tom & <b>Jerry</b>', 'subheading' => null, 'image' => null, 'button_label' => null, 'button_url' => null]],
        ]];
        $page = Page::create(['author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'draft', 'content' => null, 'blocks' => $blocks]);

        $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => 'P', 'status' => 'draft', 'content' => self::DANGEROUS])
            ->assertSessionHasNoErrors();

        $this->assertSame($blocks, $page->fresh()->blocks); // plain-text heading not run through the HTML sanitiser
        $this->assertClean($page->fresh()->content);
    }

    public function test_a_restored_revision_is_sanitised(): void
    {
        $post = Post::create(['author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'published', 'published_at' => now(), 'content' => '<p>Now</p>']);
        // A revision saved before content was sanitised on save.
        $old = Revision::create(['model_type' => Post::class, 'model_id' => $post->id, 'title' => 'P', 'content' => '<p>Old</p>'.self::DANGEROUS]);

        $this->actingAs($this->admin)->post(route('posts.revisions.restore', [$post, $old]))->assertRedirect();

        $this->assertClean($post->fresh()->content);
        $this->assertStringContainsString('<p>Old</p>', $post->fresh()->content);
    }

    public function test_first_save_of_unsanitised_html_creates_one_revision_and_later_saves_none(): void
    {
        // Stored before this change: "=" and "+" unencoded, which the sanitiser encodes.
        $post = Post::create(['author_id' => $this->admin->id, 'title' => 'P', 'slug' => 'p', 'status' => 'published', 'published_at' => now(), 'content' => '<p>a = b + c</p>']);
        $save = fn (string $content) => $this->actingAs($this->admin)->put("/posts/{$post->id}", ['title' => 'P', 'status' => 'published', 'content' => $content]);

        $save('<p>a = b + c</p>');
        $this->assertSame(1, $post->revisions()->count());           // the one extra revision
        $this->assertSame('<p>a = b + c</p>', $post->revisions()->first()->content);
        $this->assertSame('<p>a &#61; b &#43; c</p>', $post->fresh()->content);

        $save('<p>a = b + c</p>');                                   // as TipTap sends it again
        $save($post->fresh()->content);                              // as stored
        $this->assertSame(1, $post->revisions()->count());           // no more revisions
    }

    public function test_sanitize_command_dry_run_reports_and_real_run_fixes(): void
    {
        Post::create(['author_id' => $this->admin->id, 'title' => 'Bad', 'slug' => 'bad', 'status' => 'draft', 'content' => self::DANGEROUS]);
        Post::create(['author_id' => $this->admin->id, 'title' => 'Fine', 'slug' => 'fine', 'status' => 'draft', 'content' => '<p>fine</p>']);
        Page::create(['author_id' => $this->admin->id, 'title' => 'Bad page', 'slug' => 'bad-page', 'status' => 'draft', 'content' => '<script>x</script><p>p</p>']);

        $this->artisan('content:sanitize', ['--dry-run' => true])
            ->expectsOutput('1 posts would change.')
            ->expectsOutput('1 pages would change.')
            ->assertSuccessful();
        $this->assertStringContainsString('<script', Post::where('slug', 'bad')->value('content')); // dry run changed nothing

        $this->artisan('content:sanitize')->expectsOutput('1 posts sanitised.')->expectsOutput('1 pages sanitised.')->assertSuccessful();
        $this->assertClean(Post::where('slug', 'bad')->value('content'));
        $this->assertSame('<p>p</p>', Page::where('slug', 'bad-page')->value('content'));

        $this->artisan('content:sanitize', ['--dry-run' => true])->expectsOutput('0 posts would change.')->assertSuccessful();
    }
}
