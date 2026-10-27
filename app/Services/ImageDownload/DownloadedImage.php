<?php

namespace App\Services\ImageDownload;

/** A downloaded, checked image in a temporary file (the caller moves or deletes it). */
final class DownloadedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
        /** Sanitised base name from the URL plus the extension of the detected type. */
        public readonly string $fileName,
    ) {}

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
