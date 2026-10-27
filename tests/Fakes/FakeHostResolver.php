<?php

namespace Tests\Fakes;

use App\Services\ImageDownload\HostResolver;
use App\Services\ImageDownload\SystemHostResolver;

/**
 * DNS for tests: host => addresses. IP literals and numeric spellings
 * (2130706433) resolve to themselves, as with the real resolver, without any
 * lookup. Other unknown hosts resolve to a public documentation-free address.
 */
class FakeHostResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    public function __construct(private array $records = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        if (isset($this->records[$host])) {
            return $this->records[$host];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return [SystemHostResolver::numericIpv4($host) ?? '93.184.215.14'];
    }
}
