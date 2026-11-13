<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * privacy-notice:seed: the default privacy notice (App\Support\PrivacyNotice) for
 * a site that already has accounts, which the first-account trigger never reaches.
 */
class SeedPrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_site_without_privacy_gets_the_draft_from_its_first_admin(): void
    {
        $editor = User::factory()->editor()->create();
        $first = User::factory()->create(['role' => 'admin', 'email' => 'first@client.example']);
        User::factory()->create(['role' => 'admin', 'email' => 'second@client.example']);
        Page::create(['author_id' => $first->id, 'title' => 'About', 'slug' => 'about', 'content' => '<p>Hi</p>', 'status' => 'published', 'published_at' => now()->subDay()]);

        $this->artisan('privacy-notice:seed')
            ->expectsOutputToContain('A draft privacy notice was added under Pages ("Privacy", /privacy), credited to first@client.example.')
            ->assertSuccessful();

        $page = Page::where('slug', PrivacyNotice::SLUG)->sole();
        $this->assertSame(['Privacy', 'draft', $first->id], [$page->title, $page->status, $page->author_id]);
        $this->assertSame(['text'], array_column($page->blocks['blocks'], 'type'));
        $this->assertStringContainsString('after 90 days', $page->blocks['blocks'][0]['data']['html']);
        $this->assertTrue(PrivacyNotice::containsPlaceholder($page->blocks));
        $this->assertNotSame($editor->id, $page->author_id);
        $this->assertSame(2, Page::count(), 'Nothing else was added or changed');
    }

    public function test_the_author_can_be_chosen(): void
    {
        User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->editor()->create(['email' => 'editor@client.example']);

        $this->artisan('privacy-notice:seed', ['--author' => ' Editor@Client.example '])->assertSuccessful();

        $this->assertSame($editor->id, Page::where('slug', 'privacy')->value('author_id'));
    }

    public function test_a_site_that_already_has_privacy_is_left_alone_and_told_why(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $own = Page::create(['author_id' => $admin->id, 'title' => 'Privacy', 'slug' => 'privacy', 'content' => '<p>Our own notice.</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
        $before = $own->fresh()->toArray();

        $this->artisan('privacy-notice:seed')
            ->expectsOutputToContain('A page is already at /privacy ("Privacy", published), so nothing was changed.')
            ->assertFailed();

        $this->assertSame(1, Page::count());
        $this->assertSame($before, $own->fresh()->toArray());

        // The seeded draft itself counts too: running it twice adds nothing.
        $own->delete();
        $this->artisan('privacy-notice:seed')->assertSuccessful();
        $this->artisan('privacy-notice:seed')->expectsOutputToContain('("Privacy", draft), so nothing was changed.')->assertFailed();
        $this->assertSame(1, Page::where('slug', 'privacy')->count());
    }

    public function test_it_refuses_without_an_account_to_credit_or_a_readable_retention_period(): void
    {
        $this->artisan('privacy-notice:seed')
            ->expectsOutputToContain('This site has no admin account yet. Create one with php artisan user:set-password EMAIL')
            ->assertFailed();

        User::factory()->create(['role' => 'admin']);
        $this->artisan('privacy-notice:seed', ['--author' => 'nobody@client.example'])
            ->expectsOutputToContain('There is no account with the email nobody@client.example.')
            ->assertFailed();

        config(['analytics.retention_days' => 0]);
        $this->artisan('privacy-notice:seed')
            ->expectsOutputToContain('The privacy notice was not created: analytics.retention_days (ANALYTICS_RETENTION_DAYS) is not a whole number of days')
            ->assertFailed();

        $this->assertSame(0, Page::count());
    }
}
