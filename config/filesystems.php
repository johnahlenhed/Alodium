<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],


        // Off-server copies of database backups (php artisan db:backup). Any
        // S3-compatible provider: Cloudflare R2, Hetzner, DigitalOcean Spaces, B2...
        // Only encrypted files are ever written here (App\Services\OffsiteBackup).
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            // "?:" rather than a default: an empty BACKUP_S3_REGION= in .env is '' not null.
            'region' => env('BACKUP_S3_REGION') ?: 'auto',
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT') ?: null,
            'use_path_style_endpoint' => filter_var(env('BACKUP_S3_USE_PATH_STYLE', true), FILTER_VALIDATE_BOOL),
            // Throw on failure. With false (Laravel's default) a failed upload just
            // returns false, which could make a missing backup look like a success.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
