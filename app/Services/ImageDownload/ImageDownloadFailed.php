<?php

namespace App\Services\ImageDownload;

use RuntimeException;

/** Why an image was not downloaded. The message is the reason shown in the import report. */
class ImageDownloadFailed extends RuntimeException
{
    public const NOT_ALLOWED_HOST = 'host not from the exported site';

    public const BLOCKED_ADDRESS = 'blocked address';

    public const INVALID_URL = 'invalid URL';

    public const NOT_RESOLVED = 'host not found';

    public const TOO_MANY_REDIRECTS = 'too many redirects';

    public const TIMEOUT = 'timeout';

    public const CONNECTION = 'connection failed';

    public const TOO_LARGE = 'too large';

    public const NOT_AN_IMAGE = 'not an image';

    public const TOO_MANY_PIXELS = 'too many pixels';

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
