<?php

namespace X7media\LaravelPlanetscale;

use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use X7media\LaravelPlanetscale\Connection;
use Illuminate\Http\Client\ConnectionException;

class LaravelPlanetscale
{
    protected string $baseUrl = 'https://api.planetscale.com/v1';

    public function __construct(private ?string $service_token_id = '', private ?string $service_token = '')
    {
    }

    public function getDevelopmentBranch(): string
    {
        $productionBranch = config('planetscale.production_branch');

        return match ($productionBranch) {
            'main' => 'dev',
            'staging' => 'staging-dev',
            default => throw new Exception('Unknown production branch'),
        };
    }

    public function isBranchReady(string $name): bool
    {
        return $this->get("branches/{$name}")->json('ready');
    }

    public function branchPassword(string $for): Connection
    {
        $response = $this->post("branches/{$for}/passwords");

        return new Connection(
            $response->json('access_host_url'),
            $response->json('username'),
            $response->json('plain_text')
        );
    }

    // ... rest of the existing methods remain the same ...

    private function getUrl(string $endpoint): string
    {
        $organization = config('planetscale.organization');
        $database = config('planetscale.database');

        return "{$this->baseUrl}/organizations/{$organization}/databases/{$database}/{$endpoint}";
    }

    private function get(string $endpoint, array $body = []): Response
    {
        return $this
            ->baseRequest()
            ->get($this->getUrl($endpoint), $body)
            ->throw();
    }

    private function post(string $endpoint, array $body = []): Response
    {
        return $this
            ->baseRequest()
            ->post($this->getUrl($endpoint), $body)
            ->throw();
    }

    private function baseRequest(): PendingRequest
    {
        return Http::withToken("{$this->service_token_id}:{$this->service_token}", '')
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->retry(3, 1000, function (Exception $exception, PendingRequest $request) {
                return $exception instanceof ConnectionException;
            });
    }
}