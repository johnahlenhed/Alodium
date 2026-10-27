<?php

namespace App\Services\ImageDownload;

/** Turns a host name into the IP addresses a connection could go to. Injectable so tests need no DNS. */
interface HostResolver
{
    /** @return list<string> IPv4 and IPv6 addresses; empty when the host doesn't resolve. */
    public function resolve(string $host): array;
}
