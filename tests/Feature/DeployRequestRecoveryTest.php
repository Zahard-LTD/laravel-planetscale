<?php

namespace X7media\LaravelPlanetscale\Tests\Feature;

use Illuminate\Support\Facades\Http;
use X7media\LaravelPlanetscale\Tests\TestCase;

class DeployRequestRecoveryTest extends TestCase
{
    private string $base_url = 'https://api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

    private function configure(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
            'planetscale.development_branch' => 'artisan-migrate-0000000000',
        ]);
    }

    /**
     * A deploy request whose deployment_state is `no_changes` cannot be
     * deployed (PlanetScale rejects it). This is the state a second
     * back-to-back deploy lands in once the first one already merged the
     * schema: the command must close the request and succeed instead of
     * crash-looping the container (production incident 2026-08-06).
     */
    public function test_no_changes_deploy_request_is_closed_and_treated_as_success(): void
    {
        $this->configure();

        Http::preventStrayRequests();

        Http::fake(function ($request) {
            $url = $request->url();
            $method = $request->method();

            return match (true) {
                str_ends_with($url, '/branches') && $method === 'POST' => Http::response($this->getFixture('branch.success'), 201),
                str_ends_with($url, '/branches/artisan-migrate-0000000000') => Http::response($this->getFixture('branch-ready.success'), 200),
                str_ends_with($url, '/passwords') => Http::response($this->getFixture('branch-password.success'), 201),
                str_ends_with($url, '/deploy-requests') && $method === 'GET' => Http::response(['data' => []], 200),
                str_ends_with($url, '/deploy-requests') && $method === 'POST' => Http::response($this->getFixture('new-deploy-request.success'), 200),
                str_ends_with($url, '/deploy-requests/1') && $method === 'GET' => Http::response($this->getFixture('deployed.no-changes'), 200),
                str_ends_with($url, '/deploy-requests/1') && $method === 'PATCH' => Http::response($this->getFixture('deployed.success'), 200),
                default => Http::response(['message' => "Unexpected request: {$method} {$url}"], 500),
            };
        });

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        // The empty deploy request must be closed, never deployed.
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/deploy-requests/1')
                && ($request['state'] ?? null) === 'closed';
        });

        Http::assertNotSent(function ($request) {
            return str_ends_with($request->url(), '/deploy-requests/1/deploy');
        });
    }

    /**
     * PlanetScale allows a single open deploy request per branch: when a
     * previous run crashed after creating one, the next run must adopt it
     * instead of failing to create a duplicate forever.
     */
    public function test_an_existing_open_deploy_request_is_reused(): void
    {
        $this->configure();

        Http::preventStrayRequests();

        Http::fake(function ($request) {
            $url = $request->url();
            $method = $request->method();

            return match (true) {
                str_ends_with($url, '/branches') && $method === 'POST' => Http::response($this->getFixture('branch.success'), 201),
                str_ends_with($url, '/branches/artisan-migrate-0000000000') => Http::response($this->getFixture('branch-ready.success'), 200),
                str_ends_with($url, '/passwords') => Http::response($this->getFixture('branch-password.success'), 201),
                str_ends_with($url, '/deploy-requests') && $method === 'GET' => Http::response([
                    'data' => [
                        [
                            'number' => 1,
                            'state' => 'open',
                            'branch' => 'artisan-migrate-0000000000',
                            'into_branch' => 'main',
                        ],
                    ],
                ], 200),
                str_ends_with($url, '/deploy-requests/1') && $method === 'GET' => Http::response($this->getFixture('deployed.success'), 200),
                str_ends_with($url, '/deploy-requests/1/deploy') => Http::response($this->getFixture('apply-deploy-request.success'), 200),
                default => Http::response(['message' => "Unexpected request: {$method} {$url}"], 500),
            };
        });

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        // The open deploy request was adopted — no new one may be created.
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/deploy-requests');
        });
    }
}
