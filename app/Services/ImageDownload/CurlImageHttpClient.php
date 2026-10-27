<?php

namespace App\Services\ImageDownload;

/**
 * curl, connected to the address the downloader checked: CURLOPT_RESOLVE pins
 * host:port to that IP, so DNS can't change between the check and the request
 * (DNS rebinding), while the host name is still used for TLS and SNI.
 */
class CurlImageHttpClient implements ImageHttpClient
{
    public function fetch(string $url, string $host, int $port, string $ip, callable $onHeaders, callable $onChunk): FetchResult
    {
        $status = 0;
        $headers = [];

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_FOLLOWLOCATION => false, // redirects are checked hop by hop by the downloader
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', // never through a proxy from the environment
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => (int) config('import.connect_timeout'),
            CURLOPT_TIMEOUT => (int) config('import.timeout'),
            CURLOPT_USERAGENT => (string) config('import.user_agent'),
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$status, &$headers, $onHeaders) {
                $length = strlen($line);
                $line = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                    $status = (int) $match[1];
                    $headers = [];
                } elseif ($line === '') {
                    // Returning a different length makes curl stop.
                    return $onHeaders($status, $headers) ? $length : -1;
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => fn ($handle, string $chunk) => $onChunk($chunk) ? strlen($chunk) : 0,
        ]);

        curl_exec($handle);
        $errno = curl_errno($handle);
        $message = curl_error($handle);
        curl_close($handle);

        $error = match (true) {
            $errno === 0 => null,
            // A callback returned false (a limit was hit, or a redirect or error status).
            $errno === CURLE_WRITE_ERROR => 'aborted',
            $errno === CURLE_OPERATION_TIMEDOUT => 'timeout',
            in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true) => 'connect',
            // 35 SSL connect error, 51/60 certificate not trusted, 58 local certificate problem.
            in_array($errno, [35, 51, 58, 60], true) => 'tls',
            default => 'other',
        };

        return new FetchResult($status, $error, $message);
    }
}
