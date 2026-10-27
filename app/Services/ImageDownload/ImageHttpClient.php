<?php

namespace App\Services\ImageDownload;

/**
 * One HTTP GET to an address that has already been checked. Injectable so tests
 * need no network. The client follows no redirects and enforces no limits
 * itself: it hands the status, headers and body chunks to the callbacks, and
 * stops as soon as one of them returns false. The limits live in the downloader.
 */
interface ImageHttpClient
{
    /**
     * @param  string  $ip  the checked address to connect to (the host name is kept for TLS and SNI)
     * @param  callable(int $status, array<string, string> $headers): bool  $onHeaders  lower-case header names
     * @param  callable(string $chunk): bool  $onChunk
     */
    public function fetch(string $url, string $host, int $port, string $ip, callable $onHeaders, callable $onChunk): FetchResult;
}
