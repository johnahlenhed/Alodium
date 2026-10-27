<?php

namespace Tests\Fakes;

use App\Services\ImageDownload\FetchResult;
use App\Services\ImageDownload\ImageHttpClient;

/**
 * HTTP for tests: URL => response. A response is ['status' => 200, 'headers' =>
 * [...], 'body' => '...'], or ['error' => 'timeout']; 'during' => fn () runs while it downloads. The body is streamed in
 * chunks through the same callbacks curl uses, so the downloader's byte cap is
 * what stops an oversized body, exactly as in production. Unknown URLs get a 404.
 */
class FakeImageHttpClient implements ImageHttpClient
{
    /** @var list<array{url: string, host: string, port: int, ip: string}> */
    public array $requests = [];

    /** Total body bytes handed to the downloader (shows where streaming stopped). */
    public int $bytesSent = 0;

    public function __construct(private array $responses = [], private int $chunkSize = 65536) {}

    public function set(string $url, array $response): void
    {
        $this->responses[$url] = $response;
    }

    public function fetch(string $url, string $host, int $port, string $ip, callable $onHeaders, callable $onChunk): FetchResult
    {
        $this->requests[] = compact('url', 'host', 'port', 'ip');
        $response = $this->responses[$url] ?? ['status' => 404, 'body' => 'Not found'];

        // A side effect while the download is in progress (e.g. an edit in the admin).
        if (isset($response['during'])) {
            ($response['during'])();
        }

        if (isset($response['error'])) {
            return new FetchResult(0, $response['error']);
        }

        $status = $response['status'] ?? 200;
        $body = $response['body'] ?? '';
        $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
        // Real servers usually send a length; 'length' => null leaves it out, a number lies.
        if (array_key_exists('length', $response)) {
            if ($response['length'] !== null) {
                $headers['content-length'] = (string) $response['length'];
            }
        } else {
            $headers['content-length'] = (string) strlen($body);
        }

        if (! $onHeaders($status, $headers)) {
            return new FetchResult($status, 'aborted');
        }
        foreach (str_split($body, $this->chunkSize) ?: [] as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $this->bytesSent += strlen($chunk);
            if (! $onChunk($chunk)) {
                return new FetchResult($status, 'aborted');
            }
        }

        return new FetchResult($status);
    }
}
