<?php

namespace Tests\Fakes;

use App\Services\ImageDownload\HostResolver;

/** DNS for tests: host => addresses. Unknown hosts resolve to a public documentation-free address. */
class FakeHostResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    public function __construct(private array $records = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[$host] ?? ['93.184.215.14'];
    }
}
