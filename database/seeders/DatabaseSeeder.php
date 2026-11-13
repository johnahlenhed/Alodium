<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The seed data (a login with password "password", fake analytics) must
        // never reach a live site, even if someone runs db:seed --force there.
        if (app()->isProduction()) {
            // ?-> because $this->command is null when the seeder is called outside Artisan.
            $this->command?->warn('Seeders are disabled in production. Create the first admin with php artisan user:set-password EMAIL.');

            return;
        }

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // The draft privacy notice every new site starts with (user:set-password does the same).
        PrivacyNotice::createDraft($admin);

        $this->call(VisitSeeder::class);
    }
}
