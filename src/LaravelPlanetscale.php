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

    public function __construct(private ?string $service_token_id = '', private ?string $service_token = '') {}

    public function getDevelopmentBranch(): string
    {
        $developmentBranch = config('planetscale.development_branch');
        if ($developmentBranch) {
            return $developmentBranch;
        }

        $productionBranch = config('planetscale.production_branch');

        return match ($productionBranch) {
            'main' => 'dev',
            'staging' => 'staging-dev',
            default => "{$productionBranch}-dev",
        };
    }

    public function isBranchReady(string $name): bool
    {
        return $this->get("branches/{$name}")->json('ready');
    }

    public function branchExists(string $name): bool
    {
        try {
            $this->get("branches/{$name}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function createBranch(string $name, string $parent): void
    {
        $this->post('branches', [
            'name' => $name,
            'parent_branch' => $parent,
        ]);
    }

    public function ensureBranchExists(string $name, string $parent): void
    {
        if ($this->branchExists($name)) {
            while (!$this->isBranchReady($name)) {
                sleep(2);
            }
            return;
        }

        $this->createBranch($name, $parent);

        while (!$this->isBranchReady($name)) {
            sleep(2);
        }
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

    public function deployRequest(string $from): ?int
    {
        $response = $this->post('deploy-requests', [
            'branch' => $from,
            'into_branch' => config('planetscale.production_branch')
        ]);

        return ($response->successful()) ? $response->json('number') : null;
    }

    /**
     * Find an already-open deploy request for the given branch, if any.
     *
     * PlanetScale allows only one open deploy request per branch: if a previous
     * run crashed between creating and completing its deploy request, creating
     * a new one fails until the stale one is closed. Reusing it makes the
     * migration flow self-healing.
     */
    public function openDeployRequestNumber(string $branch): ?int
    {
        // Server-side filters keep the result on page one even when the closed
        // deploy-request history grows large; the client-side checks below stay
        // as a safety net in case a filter is ignored.
        $response = $this->get('deploy-requests', [
            'state' => 'open',
            'branch' => $branch,
            'into_branch' => config('planetscale.production_branch'),
        ]);

        foreach ($response->json('data') ?? [] as $request) {
            if (($request['state'] ?? null) === 'open'
                && ($request['branch'] ?? null) === $branch
                && ($request['into_branch'] ?? null) === config('planetscale.production_branch')) {
                return $request['number'];
            }
        }

        return null;
    }

    public function closeDeployRequest(int $number): void
    {
        $this->patch("deploy-requests/{$number}", ['state' => 'closed']);
    }

    public function deploymentState(int $number): string
    {
        return $this->get("deploy-requests/{$number}")->json('deployment_state');
    }

    public function completeDeploy(int $number): void
    {
        $this->post("deploy-requests/{$number}/deploy");
    }

    public function skipRevertPeriod(int $number): void
    {
        $this->post("deploy-requests/{$number}/skip-revert");
    }

    public function deleteBranch(string $name): void
    {
        $this->baseRequest()->delete($this->getUrl("branches/{$name}"))->throw();
    }

    public function runMigrations(): bool
    {
        return (App::environment() != 'testing');
    }

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

    private function patch(string $endpoint, array $body = []): Response
    {
        return $this
            ->baseRequest()
            ->patch($this->getUrl($endpoint), $body)
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
