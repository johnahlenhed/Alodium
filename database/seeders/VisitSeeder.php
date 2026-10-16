<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Visit;
use Illuminate\Database\Seeder;

class VisitSeeder extends Seeder
{
    public function run(): void
    {
        // Also blocked here, not only in DatabaseSeeder: db:seed --class=VisitSeeder
        // runs this seeder directly and would otherwise write fake analytics.
        if (app()->isProduction()) {
            $this->command?->warn('VisitSeeder is disabled in production.');

            return;
        }

        $urls = ['/', '/blog', '/about', '/contact', '/blog/first-post'];
        $referrers = ['google.com', 'twitter.com', 'instagram.com', null];
        $sources = ['google', 'instagram', 'newsletter', null];
        $devices = ['mobile', 'desktop'];
        $countries = ['SE', 'US', 'GB', 'DE', 'NO'];

        for ($i = 0; $i < 200; $i++) {
            $visit = new Visit([
                'url' => $urls[array_rand($urls)],
                'referrer' => $referrers[array_rand($referrers)],
                'utm_source' => $sources[array_rand($sources)],
                'device_type' => $devices[array_rand($devices)],
                'country' => $countries[array_rand($countries)],
                'language' => 'en',
                'duration' => random_int(10, 300),
                'scroll_depth' => random_int(10, 100),
            ]);
            $visit->screen_width = $visit->device_type === 'mobile' ? 390 : 1440;

            // created_at isn't fillable (the DB normally fills it), so set it directly.
            $visit->created_at = now()->subSeconds(random_int(0, 30 * 24 * 60 * 60));
            $visit->save();

            if (random_int(1, 100) <= 25) {
                $event = new Event([
                    'visit_id' => $visit->id,
                    'type' => 'outbound_click',
                    'payload' => ['url' => 'https://github.com/'],
                ]);
                $event->created_at = $visit->created_at->addSeconds(random_int(5, 60));
                $event->save();
            }
        }
    }
}
