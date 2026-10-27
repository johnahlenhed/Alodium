<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageDownload\DownloadedImage;
use App\Services\ImageDownload\ImageDownloadFailed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns an image URL from imported content into a file in the media library,
 * and returns the relative /storage/... path to put in the content; or
 * attaches an imported featured image to its post or page.
 *
 * An image already downloaded (by this run, an earlier import, or another post
 * sharing it) is reused: the media item remembers its source URL as
 * custom_properties.source_hash. Otherwise the run's limits are checked and the
 * image is downloaded with SafeImageDownloader: content images into the
 * importing user's 'library' collection, featured images into the item's
 * 'featured' collection. The usual conversions run on both.
 */
class ImportedImageStore
{
    public function __construct(private SafeImageDownloader $downloader) {}

    /** @throws ImageDownloadFailed with the reason the image was skipped */
    public function localPath(ImportRun $run, string $url): string
    {
        $hash = self::hash($url);

        return $this->locked($hash, function () use ($run, $url, $hash) {
            $existing = $this->existing($hash, ['library']);
            if ($existing) {
                $run->addTo('images_reused');

                return $this->path($existing);
            }

            $media = $this->download($run, $url, function (DownloadedImage $image) use ($run, $url, $hash) {
                $user = User::find($run->user_id) ?? throw ImageDownloadFailed::because('the importing user no longer exists');

                return $this->adder($user, $image, $url, $hash)->toMediaCollection('library');
            });

            return $this->path($media);
        });
    }

    /**
     * The featured image of an imported post or page. A copy is made when the
     * same image is already in the library or featured on another item: a
     * featured image belongs to its item and is deleted with it, so it is never
     * shared. Already attached (a job run again) means nothing more to do.
     *
     * @throws ImageDownloadFailed with the reason the image was skipped
     */
    public function attachFeatured(ImportRun $run, HasMedia $record, string $url): void
    {
        $hash = self::hash($url);

        $this->locked($hash, function () use ($run, $record, $url, $hash) {
            if ($record->getMedia('featured')->contains(fn (Media $media) => $media->getCustomProperty('source_hash') === $hash)) {
                return;
            }

            $existing = $this->existing($hash, ['library', 'featured']);
            if ($existing) {
                $existing->copy($record, 'featured');
                $run->addTo('images_reused');

                return;
            }

            $this->download($run, $url, fn (DownloadedImage $image) => $this->adder($record, $image, $url, $hash)->toMediaCollection('featured'));
        });
    }

    private static function hash(string $url): string
    {
        return sha1(SafeImageDownloader::normaliseUrl($url));
    }

    /** One download per image at a time, so two jobs sharing an image don't both fetch it. */
    private function locked(string $hash, callable $callback): mixed
    {
        try {
            return Cache::lock("import-image:{$hash}", 120)->block(60, $callback);
        } catch (LockTimeoutException) {
            throw ImageDownloadFailed::because('busy: the same image was still downloading elsewhere');
        }
    }

    /**
     * Checks the run's limits, downloads the image and hands it to $store, which
     * adds it to the media library. Counts it on the run.
     *
     * @param  callable(DownloadedImage): Media  $store
     */
    private function download(ImportRun $run, string $url, callable $store): Media
    {
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

            $media = $store($image);
        } catch (\Throwable $e) {
            $image?->delete();
            $run->releaseDownload();
            throw $e instanceof ImageDownloadFailed ? $e : ImageDownloadFailed::because('could not be stored');
        }

        $run->addTo('bytes_downloaded', $image->bytes);

        return $media;
    }

    private function adder(HasMedia $model, DownloadedImage $image, string $url, string $hash): FileAdder
    {
        return $model->addMedia($image->path)
            ->usingFileName($image->fileName)
            ->usingName(pathinfo($image->fileName, PATHINFO_FILENAME))
            ->withCustomProperties(['source_url' => $url, 'source_hash' => $hash]);
    }

    /**
     * An image downloaded from the same URL before, in one of the collections:
     * 'library' (a user's, from content images) or 'featured' (a post's or page's).
     * custom_properties->source_hash compiles to JSON_EXTRACT on MySQL (and SQLite).
     */
    private function existing(string $hash, array $collections): ?Media
    {
        return Media::query()
            ->whereIn('collection_name', $collections)
            ->whereIn('model_type', [(new User)->getMorphClass(), (new Post)->getMorphClass(), (new Page)->getMorphClass()])
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
