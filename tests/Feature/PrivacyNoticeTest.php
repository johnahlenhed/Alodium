<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The privacy notice a new site starts with (App\Support\PrivacyNotice): a draft
 * block page at /privacy, made with the site's first admin, edited and published
 * like any other page; the footer links to it once it is published.
 */
class PrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    private function createAdmin(string $email): PendingCommand
    {
        return $this->artisan('user:set-password', ['email' => $email, '--name' => 'Owner'])
            ->expectsConfirmation("There is no user with the email {$email}. Create a new admin account?", 'yes')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Repeat the password', self::PASSWORD);
    }

    private function notice(): ?Page
    {
        return Page::where('slug', PrivacyNotice::SLUG)->first();
    }

    private function html(Page $page): string
    {
        return $page->blocks['blocks'][0]['data']['html'];
    }

    /** The notice's blocks with the contact placeholder replaced, as an owner would. */
    private function filledIn(Page $page): array
    {
        $blocks = $page->blocks['blocks'];
        $blocks[0]['data']['html'] = str_replace(PrivacyNotice::CONTACT_PLACEHOLDER, 'Write to us through the contact form.', $blocks[0]['data']['html']);

        return $blocks;
    }

    private function save(Page $page, array $data): TestResponse
    {
        return $this->actingAs(User::sole())->put("/pages/{$page->id}", ['title' => $page->title, ...$data]);
    }

    public function test_the_first_admin_of_a_new_site_gets_a_draft_notice_with_the_retention_period(): void
    {
        config(['analytics.retention_days' => 45]);

        $this->createAdmin('owner@client.example')
            ->expectsOutputToContain('A draft privacy notice was added under Pages ("Privacy", /privacy).')
            ->assertSuccessful();

        $page = $this->notice();
        $this->assertNotNull($page);
        $this->assertSame(['Privacy', 'privacy', 'draft', User::sole()->id], [$page->title, $page->slug, $page->status, $page->author_id]);
        $this->assertNull($page->published_at);

        // One text block, as the editor makes it (an id, sanitised HTML), holding every section.
        $this->assertSame(['text'], array_column($page->blocks['blocks'], 'type'));
        $this->assertNotEmpty($page->blocks['blocks'][0]['id']);
        $html = $this->html($page);
        foreach (['What we collect', 'What we don’t collect', 'IP address', 'Retention', 'Third parties', 'Questions'] as $heading) {
            $this->assertStringContainsString("<h2>{$heading}</h2>", $html);
        }
        $this->assertStringContainsString('Visit records are deleted automatically after 45 days.', $html);
        $this->assertStringNotContainsString('90', $html);
        // No made-up address: the placeholder, word for word.
        $this->assertStringContainsString('<p>[Add your contact details here before publishing.]</p>', $html);
        $this->assertStringNotContainsString('@', $html);

        // A draft: not on the public site, and the footer doesn't link to it yet.
        $this->getJson('/api/public/pages/privacy')->assertNotFound();
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', null);
    }

    public function test_the_retention_period_comes_from_config_when_it_is_left_at_the_default(): void
    {
        $this->assertSame(90, config('analytics.retention_days'));
        $this->createAdmin('owner@client.example')->assertSuccessful();

        $this->assertStringContainsString('after 90 days', $this->html($this->notice()));
    }

    public function test_later_admins_and_sites_that_already_have_a_privacy_page_get_nothing(): void
    {
        // A site with an account already (an existing site): a new admin adds no page.
        User::factory()->create();
        $this->createAdmin('second@client.example')->doesntExpectOutputToContain('privacy notice')->assertSuccessful();
        $this->assertNull($this->notice());

        // A page of its own at /privacy is never replaced or changed.
        $own = Page::create(['author_id' => User::first()->id, 'title' => 'Our privacy', 'slug' => 'privacy', 'content' => '<p>Ours</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->assertNull(PrivacyNotice::createDraft(User::first()));
        $this->assertSame(1, Page::where('slug', 'privacy')->count());
        $this->assertSame('<p>Ours</p>', $own->fresh()->content);
    }

    public function test_the_retention_period_is_never_guessed(): void
    {
        config(['analytics.retention_days' => 0]);

        // The account is still made; the notice isn't, and the command says why.
        $this->createAdmin('owner@client.example')
            ->expectsOutputToContain('The default privacy notice was not created: analytics.retention_days (ANALYTICS_RETENTION_DAYS) is not a whole number of days')
            ->assertSuccessful();
        $this->assertSame(1, User::count());
        $this->assertNull($this->notice());

        $this->expectException(RuntimeException::class);
        PrivacyNotice::createDraft(User::sole());
    }

    public function test_the_local_seeder_adds_it_too(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $page = $this->notice();
        $this->assertSame(['draft', User::where('email', 'admin@example.com')->value('id')], [$page->status, $page->author_id]);
    }

    public function test_it_is_edited_and_published_in_the_block_editor_and_the_footer_link_follows(): void
    {
        $this->createAdmin('owner@client.example')->assertSuccessful();
        $admin = User::sole();
        $page = $this->notice();

        $this->actingAs($admin)->get("/pages/{$page->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->component('Pages/Edit')
                ->where('page.slug', 'privacy')
                ->where('page.blocks.blocks.0.type', 'text'));

        // The owner fills in the contact details and publishes it, as with any page.
        $blocks = $page->blocks['blocks'];
        $blocks[0]['data']['html'] = str_replace(PrivacyNotice::CONTACT_PLACEHOLDER, 'Write to us through the contact form.', $blocks[0]['data']['html']);
        $this->actingAs($admin)->put("/pages/{$page->id}", ['title' => $page->title, 'status' => 'published', 'blocks' => $blocks])
            ->assertSessionHasNoErrors();

        $this->getJson('/api/public/pages/privacy')->assertOk()->assertJsonPath('blocks.0.type', 'text');
        $this->assertStringContainsString('Write to us through the contact form.', $this->getJson('/api/public/pages/privacy')->json('blocks.0.data.html'));
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', ['slug' => 'privacy']);
        // Saving in the editor keeps the address: it follows the title, and "Privacy" gives /privacy.
        $this->assertSame('privacy', $page->fresh()->slug);

        // Back to a draft: the footer link goes with it.
        $this->actingAs($admin)->put("/pages/{$page->id}", ['title' => $page->title, 'status' => 'draft', 'blocks' => $blocks])->assertSessionHasNoErrors();
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', null);
    }

    public function test_renaming_it_moves_it_like_any_page_and_the_footer_drops_the_link(): void
    {
        $this->createAdmin('owner@client.example')->assertSuccessful();
        $page = $this->notice();

        $this->actingAs(User::sole())->put("/pages/{$page->id}", ['title' => 'Integritetspolicy', 'status' => 'published', 'blocks' => $this->filledIn($page)])
            ->assertSessionHasNoErrors();

        $this->assertSame('integritetspolicy', $page->fresh()->slug);
        $this->getJson('/api/public/pages/integritetspolicy')->assertOk();
        // The sentence stays, without a link, rather than linking to an address that is gone.
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', null);
    }

    public function test_it_cannot_be_published_or_scheduled_while_the_placeholder_is_in_it(): void
    {
        $this->createAdmin('owner@client.example')->assertSuccessful();
        $page = $this->notice();

        foreach ([
            'published' => ['status' => 'published'],
            'scheduled' => ['status' => 'scheduled', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')],
            // Renamed away from /privacy in the same save: still the notice.
            'published under another title' => ['status' => 'published', 'title' => 'Integritetspolicy'],
        ] as $case => $data) {
            $this->save($page, ['blocks' => $page->blocks['blocks'], ...$data])
                ->assertSessionHasErrors(['status' => PrivacyNotice::PUBLISH_BLOCKED]);
            $this->assertSame(['draft', 'privacy', null, null], [$page->fresh()->status, $page->fresh()->slug, $page->fresh()->published_at, $page->fresh()->publish_at], "{$case}: nothing was saved");
        }
        // The form without the blocks (they stay as stored): the stored placeholder counts.
        $this->save($page, ['status' => 'published'])->assertSessionHasErrors(['status' => PrivacyNotice::PUBLISH_BLOCKED]);

        $this->getJson('/api/public/pages/privacy')->assertNotFound();
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', null);
    }

    public function test_it_can_be_saved_as_a_draft_with_the_placeholder_and_published_once_it_is_replaced(): void
    {
        $this->createAdmin('owner@client.example')->assertSuccessful();
        $page = $this->notice();

        // Work in progress: saving the draft, placeholder and all, is fine.
        $blocks = $page->blocks['blocks'];
        $blocks[0]['data']['html'] .= '<p>More to come.</p>';
        $this->save($page, ['status' => 'draft', 'blocks' => $blocks])->assertSessionHasNoErrors();
        $this->assertStringContainsString('More to come.', $this->html($page->fresh()));
        $this->assertSame('draft', $page->fresh()->status);

        // Replaced: it publishes.
        $this->save($page, ['status' => 'published', 'blocks' => $this->filledIn($page->fresh())])->assertSessionHasNoErrors();
        $this->assertSame('published', $page->fresh()->status);
        $this->getJson('/api/public/site')->assertJsonPath('privacy_page', ['slug' => 'privacy']);
    }

    public function test_other_pages_are_not_affected(): void
    {
        $this->createAdmin('owner@client.example')->assertSuccessful();
        $other = Page::create(['author_id' => User::sole()->id, 'title' => 'About', 'slug' => 'about', 'content' => '<p>Quoting it: '.PrivacyNotice::CONTACT_PLACEHOLDER.'</p>', 'status' => 'draft']);

        $this->actingAs(User::sole())->put("/pages/{$other->id}", ['title' => 'About', 'status' => 'published', 'content' => $other->content])
            ->assertSessionHasNoErrors();
        $this->assertSame('published', $other->fresh()->status);
    }

    public function test_the_slug_is_free(): void
    {
        $this->assertSame('privacy', PrivacyNotice::SLUG);
        $this->assertNull(Page::reservedSlugOwner('privacy'));
        $this->assertNotContains('privacy', Page::RESERVED_SLUGS);
        // No route of the public site's own at /privacy.
        $this->assertSame([], glob(base_path('astro/src/pages/privacy*')));
    }
}
