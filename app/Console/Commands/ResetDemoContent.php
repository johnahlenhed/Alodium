<?php

namespace App\Console\Commands;

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
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Empties this instance's content, for building demo content from scratch:
 * posts, pages (with their revisions and media files), the media library,
 * the menu, contact form submissions and WordPress import reports, and resets
 * the site settings (name, description, logo, colours, front page) to their
 * defaults. Users and the visitor statistics are kept.
 *
 * Posts, pages and media are deleted one by one through their models, so the
 * model events run: HasRevisions deletes the revisions, Spatie deletes the
 * files (with their conversions), and PublicContentObserver queues a rebuild
 * of the public site.
 *
 * Guards, all needed before anything is deleted:
 * - --force. Without it, the command only shows what it would delete.
 * - --site=HOST, the host of this instance's APP_URL. APP_ENV can't tell a demo
 *   site from a client's (both are production), but naming the site you mean
 *   catches running it on the wrong server or with the wrong .env.
 * - A y/n question at a terminal, after the counts (answered "no" by default).
 *   --no-interaction skips it, for scripts that have given the other two.
 * - A database backup (db:backup) first; if it fails, nothing is deleted.
 *   --no-backup goes ahead without one (db:backup needs MySQL, so locally).
 */
class ResetDemoContent extends Command
{
    protected $signature = 'demo:reset-content
        {--force : Delete (without it, only show what would be deleted)}
        {--site= : The host of this instance\'s APP_URL, to confirm which site is reset}
        {--no-backup : Don\'t make a database backup first}';

    protected $description = 'Delete all content and reset the site settings, keeping users and visitor statistics (for demo content)';

    public function handle(): int
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $connection = config('database.default');

        $this->line("Site: <options=bold>{$host}</> (APP_ENV=".app()->environment().", database connection \"{$connection}\")");
        $this->newLine();
        $this->table(['Deleted', 'How many'], $this->counts());
        $this->line('Kept: '.User::count().' users, '.Visit::count().' visits and '.Event::count().' visitor events.');
        $this->newLine();

        if (! $this->option('force')) {
            $this->error('Nothing was deleted. To delete all of the above, run:');
            $this->line("  php artisan demo:reset-content --force --site={$host}");

            return self::FAILURE;
        }
        if ($this->option('site') !== $host) {
            $this->error('Nothing was deleted: --site must name this site, '.($host !== '' ? "{$host} (the host of APP_URL)" : 'the host of APP_URL').
                ($this->option('site') ? ', not "'.$this->option('site').'"' : '').'. If that is not the site you meant, you are on the wrong server or using the wrong .env.');

            return self::FAILURE;
        }
        if ($this->input->isInteractive() && ! $this->confirm("Delete all of this from {$host}? Media files are deleted from disk and can't be restored from the database backup.", false)) {
            $this->line('Nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('no-backup')) {
            $this->line('Backing up the database first...');
            if ($this->call('db:backup') !== self::SUCCESS) {
                $this->error('Nothing was deleted: the database backup failed (above). Fix it, or run again with --no-backup to go ahead without one.');

                return self::FAILURE;
            }
        }

        $this->reset();

        $this->newLine();
        $this->info("The content of {$host} has been deleted and the site settings reset. The public site is rebuilt in the background.");

        return self::SUCCESS;
    }

    /** @return list<array{string, int|string}> */
    private function counts(): array
    {
        $contentMedia = Media::whereIn('model_type', [Post::class, Page::class])->count();
        $settings = SiteSetting::query()->find(SiteSetting::ID);

        return [
            ['Posts', Post::count()],
            ['Pages', Page::count()],
            ['Revisions of posts and pages', Revision::count()],
            ['Media files of posts and pages (featured images, imported images)', $contentMedia],
            ['Media library uploads', $this->libraryMedia()->count()],
            ['Menu links', NavigationItem::count()],
            ['Contact form submissions', Submission::count()],
            ['WordPress import reports', ImportRun::count()],
            ['Site settings', $settings ? "reset to defaults (now \"{$settings->site_name}\"".($settings->logo() ? ', with a logo' : '').')' : 'already the defaults'],
        ];
    }

    private function libraryMedia()
    {
        return Media::where('model_type', User::class)->where('collection_name', 'library');
    }

    private function reset(): void
    {
        // One at a time through the model, so every model event runs (see the class comment).
        Post::query()->lazyById()->each(fn (Post $post) => $post->delete());
        Page::query()->lazyById()->each(fn (Page $page) => $page->delete());
        // Media library uploads belong to users, who stay: the files go, the accounts don't.
        $this->libraryMedia()->lazyById()->each(fn (Media $media) => $media->delete());

        NavigationItem::query()->delete();
        Submission::query()->delete();
        ImportRun::query()->delete();

        // Without the row, SiteSetting::current() is the defaults; deleting it
        // through the model makes Spatie delete the logo and its files too.
        SiteSetting::query()->find(SiteSetting::ID)?->delete();

        // The menu and settings aren't page content, so nothing has queued this
        // for them (deleting published posts and pages may have; it runs once).
        RebuildAstroJob::dispatch();
    }
}
