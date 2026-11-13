<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Adds the default privacy notice (App\Support\PrivacyNotice) as a draft to a
 * site that already has accounts: a new site gets it with its first admin
 * (user:set-password), an existing one only when someone runs this. It never
 * touches a page already at /privacy.
 */
class SeedPrivacyNotice extends Command
{
    protected $signature = 'privacy-notice:seed
        {--author= : Email of the account the draft is credited to (default: the first admin)}';

    protected $description = 'Add the default privacy notice to this site as a draft at /privacy, unless a page is already there';

    public function handle(): int
    {
        $email = $this->option('author');
        $author = $email !== null
            ? User::where('email', mb_strtolower(trim((string) $email)))->first()
            : User::where('role', 'admin')->orderBy('id')->first();

        if ($author === null) {
            $this->error($email !== null
                ? "There is no account with the email {$email}."
                : 'This site has no admin account yet. Create one with php artisan user:set-password EMAIL, which also adds the privacy notice.');

            return self::FAILURE;
        }

        try {
            $page = PrivacyNotice::createDraft($author);
        } catch (RuntimeException $e) {
            $this->error('The privacy notice was not created: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($page === null) {
            $existing = Page::where('slug', PrivacyNotice::SLUG)->first();
            $this->error('A page is already at /'.PrivacyNotice::SLUG." (\"{$existing->title}\", {$existing->status}), so nothing was changed. Edit that page, or give it another title to free the address, and run this again.");

            return self::FAILURE;
        }

        $this->info('A draft privacy notice was added under Pages ("'.$page->title.'", /'.$page->slug.'), credited to '.$author->email.'.');
        $this->line('Add your contact details, check the text, and publish it: the footer links to it once it is published.');

        return self::SUCCESS;
    }
}
