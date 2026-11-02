<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $buildRecord = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The queue is sync in tests, so publishing runs RebuildAstroJob, which
        // writes the build record (PublicSiteBuilds). Never the real one in storage/app.
        $this->buildRecord = sys_get_temp_dir().'/alodium-build-record-'.bin2hex(random_bytes(6)).'.json';
        config(['services.astro.build_record' => $this->buildRecord]);
    }

    protected function tearDown(): void
    {
        if ($this->buildRecord !== null) {
            @unlink($this->buildRecord);
        }

        parent::tearDown();
    }
}
