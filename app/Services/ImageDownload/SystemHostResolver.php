<?php

namespace App\Services\ImageDownload;

/**
 * The system resolver (A records, and numeric spellings such as 2130706433 or
 * 0x7f.1, which it turns into 127.0.0.1 without any lookup) plus AAAA records.
 * Every address is returned: the downloader rejects the host if any of them
 * is not public.
 */
class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // 2130706433, 0x7f000001, 0177.0.0.1, 0x7f.1: curl and browsers read these as 127.0.0.1.
        $numeric = self::numericIpv4($host);
        if ($numeric !== null) {
            return [$numeric];
        }

        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * A numeric IPv4 spelling as inet_aton() reads it: 1 to 4 parts, each decimal,
     * octal (leading 0) or hex (0x); the last part fills the remaining bytes.
     * Null when the host isn't one (then it is a name).
     */
    public static function numericIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $value = match (true) {
                (bool) preg_match('/^0x[0-9a-f]*\z/i', $part) => hexdec(substr($part, 2) ?: '0'),
                (bool) preg_match('/^0[0-7]+\z/', $part) => octdec($part),
                (bool) preg_match('/^[0-9]+\z/', $part) => (int) $part,
                default => null,
            };
            if ($value === null) {
                return null;
            }
            $numbers[] = $value;
        }

        // Every part but the last is one byte; the last fills what is left.
        $last = array_pop($numbers);
        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }
        $remainingBytes = 4 - count($numbers);
        if ($last >= 256 ** $remainingBytes) {
            return null;
        }

        $value = 0;
        foreach ($numbers as $number) {
            $value = ($value << 8) | $number;
        }
        $value = ($value << (8 * $remainingBytes)) | $last;

        return long2ip($value);
    }
}
