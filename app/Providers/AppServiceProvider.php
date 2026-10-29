<?php

namespace App\Providers;

use App\Services\ImageDownload\CurlImageHttpClient;
use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\ImageHttpClient;
use App\Services\ImageDownload\SystemHostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\IpUtils;

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

        // GET /api/public/*: no browser calls these (the public site is static HTML),
        // only the Astro build does, so 60 a minute per IP only stops scrapers. The
        // build itself is never limited: it makes one request per published post and
        // page plus five, all at once, so any fixed limit breaks once the site grows.
        RateLimiter::for('public-content', fn (Request $request) => $this->fromThisServer($request)
            ? Limit::none()
            // by(): without it every client would share one counter.
            : Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * Whether the request comes from this machine. Locally the build uses
     * localhost (loopback). In production API_URL is the public admin domain, which
     * resolves to the server's own public IP: the connection then comes from that IP
     * and arrives on it, so REMOTE_ADDR equals SERVER_ADDR. A client elsewhere cannot
     * open a TCP connection from the server's own address.
     */
    private function fromThisServer(Request $request): bool
    {
        $ip = $request->ip();

        return $ip !== null
            && (IpUtils::checkIp($ip, ['127.0.0.0/8', '::1']) || $ip === $request->server('SERVER_ADDR'));
    }
}
