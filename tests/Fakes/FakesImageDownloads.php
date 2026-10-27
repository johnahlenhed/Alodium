<?php

namespace Tests\Fakes;

use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\ImageHttpClient;

/** Replaces DNS and HTTP for the importer's image downloads, and makes real test images. */
trait FakesImageDownloads
{
    protected FakeImageHttpClient $http;

    protected FakeHostResolver $dns;

    protected function fakeImageDownloads(array $responses = [], array $dns = []): void
    {
        $this->http = new FakeImageHttpClient($responses);
        $this->dns = new FakeHostResolver($dns);
        $this->app->instance(ImageHttpClient::class, $this->http);
        $this->app->instance(HostResolver::class, $this->dns);
    }

    /** A real image of the given type, as bytes. */
    protected function imageBytes(string $type = 'png', int $width = 120, int $height = 80): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    protected function imageResponse(string $type = 'png', int $width = 120, int $height = 80): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'image/'.$type], 'body' => $this->imageBytes($type, $width, $height)];
    }
}
