<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\Event;
use App\Models\ImportRun;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Revision;
use App\Models\SiteSetting;
use App\Models\Submission;
use App\Models\User;
use App\Models\Visit;
use App\Support\ThemeOptions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/** php artisan demo:reset-content: an empty site for demo content, with users and statistics kept. */
class ResetDemoContentTest extends TestCase
{
    use RefreshDatabase;

    /** The host of APP_URL in the tests (http://localhost). */
    private const SITE = 'localhost';

    private const CONFIRM = "Delete all of this from localhost? Media files are deleted from disk and can't be restored from the database backup.";

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        // Media files on a fake disk: never the real storage/app/public.
        Storage::fake('public');
        $this->admin = User::factory()->create(['name' => 'Ada Admin']);
        $this->editor = User::factory()->editor()->create(['name' => 'Ed Editor']);
    }

    /** A site with content of every kind, files on disk, and statistics. */
    private function seedSite(): void
    {
        $post = Post::create(['author_id' => $this->admin->id, 'title' => 'Old news', 'slug' => 'old-news', 'content' => '<p>v1</p>', 'status' => 'published', 'published_at' => now()]);
        $post->update(['content' => '<p>v2</p>']); // a revision
        $post->addMedia(UploadedFile::fake()->image('featured.jpg', 800, 400))->toMediaCollection('featured');

        $page = Page::create(['author_id' => $this->editor->id, 'title' => 'About the old client', 'slug' => 'about', 'content' => '<p>v1</p>', 'status' => 'published', 'published_at' => now()]);
        $page->update(['content' => '<p>v2</p>']);
        $page->addMedia(UploadedFile::fake()->image('page.png', 600, 300))->toMediaCollection('featured');
        Page::create(['author_id' => $this->admin->id, 'title' => 'Draft', 'slug' => 'draft', 'content' => '<p>d</p>', 'status' => 'draft']);

        $this->admin->addMedia(UploadedFile::fake()->image('library.jpg', 500, 500))->toMediaCollection('library');

        $settings = SiteSetting::current();
        $settings->fill(['site_name' => 'Old Client Ltd', 'site_description' => 'The old client.', 'brand_color' => '#7A1F1F', 'footer_text' => 'Old address', 'front_page_id' => $page->id])->save();
        $settings->addMedia(UploadedFile::fake()->image('logo.png', 300, 100))->toMediaCollection('logo');

        // A new site's menu already has Blog and Contact (the navigation_items migration).
        NavigationItem::create(['page_id' => $page->id, 'sort_order' => 3]);
        Submission::create(['name' => 'A visitor', 'email' => 'visitor@example.com', 'message' => 'Hello']);
        ImportRun::create(['user_id' => $this->admin->id, 'status' => ImportRun::STATUS_DONE, 'started_at' => now()]);

        $visit = new Visit(['url' => '/about']);
        $visit->save();
        Event::create(['visit_id' => $visit->id, 'type' => 'click', 'payload' => ['x' => 1]]);
    }

    private function reset(): PendingCommand
    {
        return $this->artisan('demo:reset-content', ['--force' => true, '--site' => self::SITE, '--no-backup' => true]);
    }

    public function test_it_deletes_all_content_and_its_files_and_resets_the_settings(): void
    {
        Queue::fake();
        $this->seedSite();
        $this->assertNotEmpty(Storage::disk('public')->allFiles(), 'The originals and their conversions are on disk.');
        $this->assertSame(4, Media::count());
        $this->assertSame(2, Revision::count());
        $users = User::orderBy('id')->get(['id', 'name', 'email', 'password', 'role'])->toArray();

        $this->reset()
            ->expectsOutputToContain('Old Client Ltd')
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->expectsOutputToContain('The content of localhost has been deleted')
            ->assertSuccessful();

        // Content: gone, and through the models, so revisions and media rows went too.
        foreach ([Post::class, Page::class, Revision::class, Media::class, NavigationItem::class, Submission::class, ImportRun::class] as $model) {
            $this->assertSame(0, $model::count(), $model);
        }
        // The files themselves (originals and conversions), not just the rows.
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Settings: the defaults, with no logo.
        $this->assertNull(SiteSetting::query()->find(SiteSetting::ID));
        $settings = SiteSetting::current();
        $this->assertSame(config('app.name'), $settings->site_name);
        $this->assertNull($settings->site_description);
        $this->assertNull($settings->footer_text);
        $this->assertSame(ThemeOptions::DEFAULTS['brand'], $settings->brand_color);
        $this->assertNull($settings->front_page_id);
        $this->assertNull($settings->logo());

        // Kept: every account exactly as it was, and the statistics.
        $this->assertSame($users, User::orderBy('id')->get(['id', 'name', 'email', 'password', 'role'])->toArray());
        $this->assertSame(1, Visit::count());
        $this->assertSame(1, Event::count());

        Queue::assertPushed(RebuildAstroJob::class);
    }

    public function test_without_force_it_only_shows_what_it_would_delete(): void
    {
        $this->seedSite();

        $this->artisan('demo:reset-content', ['--site' => self::SITE])
            ->expectsTable(['Deleted', 'How many'], [
                ['Posts', 1],
                ['Pages', 2],
                ['Revisions of posts and pages', 2],
                ['Media files of posts and pages (featured images, imported images)', 2],
                ['Media library uploads', 1],
                ['Menu links', 3],
                ['Contact form submissions', 1],
                ['WordPress import reports', 1],
                ['Site settings', 'reset to defaults (now "Old Client Ltd", with a logo)'],
            ])
            ->expectsOutputToContain('Kept: 2 users, 1 visits and 1 visitor events.')
            ->expectsOutputToContain('Nothing was deleted.')
            ->expectsOutputToContain('php artisan demo:reset-content --force --site=localhost')
            ->assertFailed();

        $this->assertNothingDeleted();
    }

    public function test_it_refuses_without_the_right_site_name(): void
    {
        $this->seedSite();

        foreach ([null, 'demo.client.example'] as $site) {
            $this->artisan('demo:reset-content', array_filter(['--force' => true, '--site' => $site, '--no-backup' => true]))
                ->expectsOutputToContain('Nothing was deleted: --site must name this site, localhost (the host of APP_URL)')
                ->assertFailed();
        }

        $this->assertNothingDeleted();
    }

    public function test_answering_no_deletes_nothing(): void
    {
        $this->seedSite();

        $this->reset()
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->expectsOutput('Nothing was deleted.')
            ->assertSuccessful();

        $this->assertNothingDeleted();
    }

    public function test_without_a_terminal_force_and_the_site_are_enough(): void
    {
        $this->seedSite();

        $this->artisan('demo:reset-content', ['--force' => true, '--site' => self::SITE, '--no-backup' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, Post::count() + Page::count());
    }

    /** The backup comes first; if it fails, nothing is deleted. */
    public function test_a_failed_backup_stops_it_and_a_good_one_lets_it_run(): void
    {
        $this->seedSite();
        $backup = $this->fakeBackup(result: Command::FAILURE);

        $this->artisan('demo:reset-content', ['--force' => true, '--site' => self::SITE])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->expectsOutputToContain('Nothing was deleted: the database backup failed')
            ->assertFailed();
        $this->assertSame(1, $backup->runs);
        $this->assertNothingDeleted();

        $backup->result = Command::SUCCESS;
        $this->artisan('demo:reset-content', ['--force' => true, '--site' => self::SITE])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();
        $this->assertSame(2, $backup->runs);
        $this->assertSame(0, Post::count());
    }

    /** db:backup needs MySQL; the tests use SQLite, so a stand-in that counts its runs. */
    private function fakeBackup(int $result): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'db:backup';

            public int $runs = 0;

            public int $result = self::SUCCESS;

            public function handle(): int
            {
                $this->runs++;

                return $this->result;
            }
        };
        $command->result = $result;
        $this->app->make(Kernel::class)->registerCommand($command);

        return $command;
    }

    private function assertNothingDeleted(): void
    {
        $this->assertSame([1, 2, 2, 4, 3, 1, 1], [Post::count(), Page::count(), Revision::count(), Media::count(), NavigationItem::count(), Submission::count(), ImportRun::count()]);
        $this->assertSame('Old Client Ltd', SiteSetting::current()->site_name);
        $this->assertNotEmpty(Storage::disk('public')->allFiles());
    }
}
