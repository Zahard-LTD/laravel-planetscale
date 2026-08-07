<?php

namespace X7media\LaravelPlanetscale\Tests\Feature;

use Illuminate\Support\Facades\Http;
use X7media\LaravelPlanetscale\Tests\TestCase;

class BranchTest extends TestCase
{
    public function test_that_a_branch_migration_succeeds(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
            'planetscale.development_branch' => 'artisan-migrate-0000000000',
        ]);

        Http::preventStrayRequests();

        $base_url = 'api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

        Http::fake([
            "{$base_url}/branches" => Http::response($this->getFixture('branch.success'), 201),
            "{$base_url}/branches/artisan-migrate-0000000000" => Http::response($this->getFixture('branch-ready.success'), 200),
            "{$base_url}/branches/artisan-migrate-0000000000/passwords" => Http::response($this->getFixture('branch-password.success'), 201),
            // The open-DR recovery lookup sends query params; return an empty
            // list so the command falls through to creating a new request.
            "{$base_url}/deploy-requests?*" => Http::response(['data' => []], 200),
            "{$base_url}/deploy-requests" => Http::response($this->getFixture('new-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1" => Http::response($this->getFixture('deployed.success'), 200),
            "{$base_url}/deploy-requests/1/deploy" => Http::response($this->getFixture('apply-deploy-request.success'), 200),
        ]);

        $this->assertNotEquals(config('database.connections.testing.username'), 'xxxxxxxxxxxxxxxxxxxx');
        $this->assertNotEquals(config('database.connections.testing.password'), 'pscale_pw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        $this->assertEquals(config('database.connections.testing.username'), 'xxxxxxxxxxxxxxxxxxxx');
        $this->assertEquals(config('database.connections.testing.password'), 'pscale_pw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    }

    public function test_skip_revert_period_is_called_when_deployment_ends_in_pending_revert(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
            'planetscale.development_branch' => 'artisan-migrate-0000000000',
            'planetscale.skip_revert_period' => true,
        ]);

        Http::preventStrayRequests();

        $base_url = 'api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

        Http::fake([
            "{$base_url}/branches" => Http::response($this->getFixture('branch.success'), 201),
            "{$base_url}/branches/artisan-migrate-0000000000" => Http::response($this->getFixture('branch-ready.success'), 200),
            "{$base_url}/branches/artisan-migrate-0000000000/passwords" => Http::response($this->getFixture('branch-password.success'), 201),
            // The open-DR recovery lookup sends query params; return an empty
            // list so the command falls through to creating a new request.
            "{$base_url}/deploy-requests?*" => Http::response(['data' => []], 200),
            "{$base_url}/deploy-requests" => Http::response($this->getFixture('new-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1" => Http::response($this->getFixture('deployed.pending-revert'), 200),
            "{$base_url}/deploy-requests/1/deploy" => Http::response($this->getFixture('apply-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1/skip-revert" => Http::response($this->getFixture('skip-revert-period.success'), 200),
        ]);

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        Http::assertSent(function ($request) use ($base_url) {
            return $request->method() === 'POST'
                && $request->url() === "https://{$base_url}/deploy-requests/1/skip-revert";
        });
    }

    public function test_skip_revert_period_is_not_called_when_disabled(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
            'planetscale.development_branch' => 'artisan-migrate-0000000000',
            'planetscale.skip_revert_period' => false,
        ]);

        Http::preventStrayRequests();

        $base_url = 'api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

        Http::fake([
            "{$base_url}/branches" => Http::response($this->getFixture('branch.success'), 201),
            "{$base_url}/branches/artisan-migrate-0000000000" => Http::response($this->getFixture('branch-ready.success'), 200),
            "{$base_url}/branches/artisan-migrate-0000000000/passwords" => Http::response($this->getFixture('branch-password.success'), 201),
            // The open-DR recovery lookup sends query params; return an empty
            // list so the command falls through to creating a new request.
            "{$base_url}/deploy-requests?*" => Http::response(['data' => []], 200),
            "{$base_url}/deploy-requests" => Http::response($this->getFixture('new-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1" => Http::response($this->getFixture('deployed.pending-revert'), 200),
            "{$base_url}/deploy-requests/1/deploy" => Http::response($this->getFixture('apply-deploy-request.success'), 200),
        ]);

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        Http::assertNotSent(function ($request) use ($base_url) {
            return $request->url() === "https://{$base_url}/deploy-requests/1/skip-revert";
        });
    }

    public function test_skip_revert_period_failure_does_not_fail_the_command(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
            'planetscale.development_branch' => 'artisan-migrate-0000000000',
            'planetscale.skip_revert_period' => true,
        ]);

        Http::preventStrayRequests();

        $base_url = 'api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

        Http::fake([
            "{$base_url}/branches" => Http::response($this->getFixture('branch.success'), 201),
            "{$base_url}/branches/artisan-migrate-0000000000" => Http::response($this->getFixture('branch-ready.success'), 200),
            "{$base_url}/branches/artisan-migrate-0000000000/passwords" => Http::response($this->getFixture('branch-password.success'), 201),
            // The open-DR recovery lookup sends query params; return an empty
            // list so the command falls through to creating a new request.
            "{$base_url}/deploy-requests?*" => Http::response(['data' => []], 200),
            "{$base_url}/deploy-requests" => Http::response($this->getFixture('new-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1" => Http::response($this->getFixture('deployed.pending-revert'), 200),
            "{$base_url}/deploy-requests/1/deploy" => Http::response($this->getFixture('apply-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1/skip-revert" => Http::response(['message' => 'forbidden'], 403),
        ]);

        // The migration itself succeeded against production. A failed
        // skip-revert cleanup must NOT fail the command, otherwise the
        // container would crash on what is already a successful deploy.
        $this->artisan('pscale:migrate')
            ->assertExitCode(0);
    }
}
