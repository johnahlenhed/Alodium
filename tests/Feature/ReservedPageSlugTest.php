<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A page whose title gives a slug the public site uses itself (/contact, /blog,
 * /404, /index) used to save as "published" and then never be built. The page
 * form now refuses such a title, with the reason.
 */
class ReservedPageSlugTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    /** Titles whose slug is reserved, with that slug. Case, spaces and punctuation don't help. */
    public static function reservedTitles(): array
    {
        return [
            'Contact' => ['Contact', 'contact'],
            'lowercase contact' => ['contact', 'contact'],
            'Blog with punctuation' => [' Blog! ', 'blog'],
            'Index' => ['Index', 'index'],
            'the 404 page' => ['404', '404'],
        ];
    }

    #[DataProvider('reservedTitles')]
    public function test_creating_a_page_with_a_reserved_title_is_refused(string $title, string $slug): void
    {
        $this->actingAs($this->admin)
            ->from('/pages/create')
            ->post('/pages', ['title' => $title, 'status' => 'published'])
            ->assertRedirect('/pages/create')
            // The request's strings are trimmed (TrimStrings) before validation.
            ->assertSessionHasErrors(['title' => Page::reservedSlugMessage(trim($title))]);

        $this->assertSame(0, Page::count());
        $this->assertStringContainsString("/{$slug},", Page::reservedSlugMessage($title));
    }

    public function test_the_message_says_why_and_what_to_do_instead(): void
    {
        $this->assertSame(
            'A page titled "Contact" would get the address /contact, which is the site\'s built-in contact page, so it would never be shown. '
            .'Choose another title, such as "Contact us". The built-in contact page is at /contact, and Settings can put it in the menu.',
            Page::reservedSlugMessage('Contact'),
        );
        $this->assertStringContainsString("the site's built-in blog", Page::reservedSlugMessage('Blog'));
        $this->assertStringContainsString('Front page under Settings', Page::reservedSlugMessage('Index'));
    }

    public function test_every_reserved_slug_has_its_own_explanation(): void
    {
        foreach (Page::RESERVED_SLUGS as $slug) {
            $owner = Page::reservedSlugOwner($slug);
            $this->assertNotNull($owner, $slug);
            $this->assertNotSame('a page the site builds itself', $owner, "/{$slug} has no description in Page::reservedSlugOwner().");
        }
        $this->assertNull(Page::reservedSlugOwner('about'));
    }

    /** Similar titles get a different slug and are fine, including folders in astro/public. */
    public function test_titles_that_only_resemble_a_reserved_slug_are_accepted(): void
    {
        foreach (['Contact us', 'Our blog', 'Blogging tips', 'Kontakt', 'Fonts', 'Home'] as $title) {
            $this->actingAs($this->admin)
                ->post('/pages', ['title' => $title, 'status' => 'published'])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing(
            ['contact-us', 'our-blog', 'blogging-tips', 'kontakt', 'fonts', 'home'],
            Page::pluck('slug')->all(),
        );
        $this->assertSame(6, Page::onPublicSite()->count());
    }

    public function test_renaming_a_page_to_a_reserved_title_is_refused_and_changes_nothing(): void
    {
        $page = $this->page('About', 'about');

        $this->actingAs($this->admin)
            ->put("/pages/{$page->id}", ['title' => 'Blog', 'status' => 'published'])
            ->assertSessionHasErrors('title');

        $this->assertSame(['About', 'about'], [$page->fresh()->title, $page->fresh()->slug]);
    }

    /** A page saved before this check: saving it asks for a new title, which then fixes the address. */
    public function test_an_existing_page_with_a_reserved_slug_can_be_renamed_to_fix_it(): void
    {
        $page = $this->page('Contact', 'contact');
        $this->assertSame(0, Page::onPublicSite()->count());

        $this->actingAs($this->admin)
            ->put("/pages/{$page->id}", ['title' => 'Contact', 'status' => 'published'])
            ->assertSessionHasErrors('title');

        $this->actingAs($this->admin)
            ->put("/pages/{$page->id}", ['title' => 'Contact us', 'status' => 'published'])
            ->assertSessionHasNoErrors();

        $this->assertSame('contact-us', $page->fresh()->slug);
        $this->assertSame(1, Page::onPublicSite()->count());
    }

    /** Posts live under /blog/, where no slug is taken: a post called "Contact" or "Index" is fine. */
    public function test_posts_are_not_affected(): void
    {
        foreach (['Contact', 'Index', 'Blog'] as $title) {
            $this->actingAs($this->admin)
                ->post('/posts', ['title' => $title, 'content' => '<p>Text</p>', 'status' => 'published'])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing(['contact', 'index', 'blog'], Post::pluck('slug')->all());
    }

    private function page(string $title, string $slug): Page
    {
        return Page::create([
            'author_id' => $this->admin->id,
            'title' => $title,
            'slug' => $slug,
            'content' => '<p>Text</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }
}
