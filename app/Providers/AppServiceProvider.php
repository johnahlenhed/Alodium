<?php

namespace App\Providers;

use App\Services\ImageDownload\CurlImageHttpClient;
use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\ImageHttpClient;
use App\Services\ImageDownload\SystemHostResolver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The WordPress importer's image downloads (App\Services\SafeImageDownloader).
        // Tests bind fakes instead, so they need no DNS or network.
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(ImageHttpClient::class, CurlImageHttpClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
