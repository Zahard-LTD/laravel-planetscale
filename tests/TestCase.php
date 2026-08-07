<?php

namespace X7media\LaravelPlanetscale\Tests;

use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    public function setUp(): void
    {
        parent::setUp();

        // The command sleeps $pollRate seconds between deploy-state polls;
        // zero it out so the suite doesn't spend ~40s sleeping.
        config(['planetscale.poll_rate' => 0]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getFixture(string $name): string
    {
        return file_get_contents("tests/Fixtures/{$name}.json");
    }

    protected function getPackageProviders($app): array
    {
        return ['X7media\LaravelPlanetscale\LaravelPlanetscaleServiceProvider'];
    }
}
