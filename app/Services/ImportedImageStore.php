<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\ImageDownload\ImageDownloadFailed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns an image URL from imported content into a file in the media library,
 * and returns the relative /storage/... path to put in the content.
 *
 * An image already downloaded (by this run, an earlier import, or another post
 * sharing it) is reused: the media item remembers its source URL as
 * custom_properties.source_hash. Otherwise the run's limits are checked and the
 * image is downloaded with SafeImageDownloader into the importing user's
 * 'library' collection, where the usual conversions run.
 */
class ImportedImageStore
{
    public function __construct(private SafeImageDownloader $downloader) {}

    /** @throws ImageDownloadFailed with the reason the image was skipped */
    public function localPath(ImportRun $run, string $url): string
    {
        $hash = sha1(SafeImageDownloader::normaliseUrl($url));

        try {
            // One download per image at a time, so two jobs sharing an image don't both fetch it.
            return Cache::lock("import-image:{$hash}", 120)->block(60, fn () => $this->reuseOrDownload($run, $url, $hash));
        } catch (LockTimeoutException) {
            throw ImageDownloadFailed::because('busy: the same image was still downloading elsewhere');
        }
    }

    private function reuseOrDownload(ImportRun $run, string $url, string $hash): string
    {
        $existing = $this->existing($hash);
        if ($existing) {
            $run->addTo('images_reused');

            return $this->path($existing);
        }

        $this->checkDiskSpace();
        $remainingBytes = (int) config('import.max_total_bytes') - (int) $run->newQuery()->whereKey($run->id)->value('bytes_downloaded');
        if ($remainingBytes <= 0) {
            throw ImageDownloadFailed::because('run limit: '.$this->size((int) config('import.max_total_bytes')).' downloaded');
        }
        if (! $run->reserveDownload((int) config('import.max_images_per_run'))) {
            throw ImageDownloadFailed::because('run limit: '.config('import.max_images_per_run').' images downloaded');
        }

        $image = null;
        try {
            try {
                $image = $this->downloader->download($url, $run->allowed_hosts ?? [], $remainingBytes);
            } catch (ImageDownloadFailed $e) {
                // Too large for what is left of the run's budget, not for the per-file cap.
                if ($e->reason() === ImageDownloadFailed::TOO_LARGE && $remainingBytes < (int) config('import.max_image_bytes')) {
                    throw ImageDownloadFailed::because('run limit: '.$this->size((int) config('import.max_total_bytes')).' downloaded');
                }
                throw $e;
            }

            $user = User::find($run->user_id) ?? throw ImageDownloadFailed::because('the importing user no longer exists');
            $media = $user->addMedia($image->path)
                ->usingFileName($image->fileName)
                ->usingName(pathinfo($image->fileName, PATHINFO_FILENAME))
                ->withCustomProperties(['source_url' => $url, 'source_hash' => $hash])
                ->toMediaCollection('library');
        } catch (\Throwable $e) {
            $image?->delete();
            $run->releaseDownload();
            throw $e instanceof ImageDownloadFailed ? $e : ImageDownloadFailed::because('could not be stored');
        }

        $run->addTo('bytes_downloaded', $image->bytes);

        return $this->path($media);
    }

    /**
     * A library image downloaded from the same URL before. Featured images aren't
     * reused here: they belong to their post and are deleted with it.
     * custom_properties->source_hash compiles to JSON_EXTRACT on MySQL (and SQLite).
     */
    private function existing(string $hash): ?Media
    {
        return Media::query()
            ->where('collection_name', 'library')
            ->where('model_type', (new User)->getMorphClass())
            ->where('custom_properties->source_hash', $hash)
            ->oldest('id')
            ->first();
    }

    /** "/storage/12/photo.jpg": the URL without its origin, the way the block image picker stores it. */
    private function path(Media $media): string
    {
        $path = (string) parse_url($media->getUrl(), PHP_URL_PATH);
        if (! str_starts_with($path, '/storage/')) {
            throw ImageDownloadFailed::because('could not be stored');
        }

        return $path;
    }

    private function checkDiskSpace(): void
    {
        $free = @disk_free_space(Storage::disk(config('media-library.disk_name'))->path(''));
        if ($free !== false && $free < (int) config('import.min_free_disk_bytes')) {
            throw ImageDownloadFailed::because('low disk space');
        }
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GiB' : round($bytes / 1024 ** 2).' MB';
    }
}
