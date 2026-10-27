<?php

/*
 * Limits for the WordPress importer's image downloads (App\Services\SafeImageDownloader,
 * App\Services\ImportedImageStore, App\Jobs\DownloadContentImages). The export
 * file may come from a third party, so every limit protects the server.
 */
return [
    // One file. The body is streamed and the download stops as soon as it is larger.
    'max_image_bytes' => (int) env('IMPORT_MAX_IMAGE_BYTES', 10 * 1024 * 1024),

    // Width x height, read from the file header before anything is decoded:
    // a small file can unpack into a huge bitmap (a decompression bomb), and the
    // conversions run in 1 GB of RAM.
    'max_megapixels' => (int) env('IMPORT_MAX_MEGAPIXELS', 25),

    // Per post or page, and per import run. Images over a limit are skipped and reported.
    'max_images_per_item' => (int) env('IMPORT_MAX_IMAGES_PER_ITEM', 50),
    'max_images_per_run' => (int) env('IMPORT_MAX_IMAGES_PER_RUN', 1500),
    'max_total_bytes' => (int) env('IMPORT_MAX_TOTAL_BYTES', 1024 * 1024 * 1024),

    // Checked before each download, on the disk that holds the media library.
    'min_free_disk_bytes' => (int) env('IMPORT_MIN_FREE_DISK_BYTES', 2 * 1024 * 1024 * 1024),

    // Seconds.
    'connect_timeout' => 5,
    'timeout' => 15,
    'max_redirects' => 3,

    // A download job stops starting new images after this many seconds and queues
    // itself again for the rest. Stays well under the worker's --timeout=660.
    'job_time_budget' => (int) env('IMPORT_JOB_TIME_BUDGET', 480),

    'user_agent' => 'Alodium WordPress importer (self-hosted CMS; downloads the images of an imported site)',
];
