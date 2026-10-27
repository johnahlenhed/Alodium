<?php

namespace App\Services\ImageDownload;

/** How a fetch ended. $error is null, 'timeout', 'connect', 'tls', 'aborted' (a callback said stop) or 'other'. */
final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly string $message = '',
    ) {}
}
