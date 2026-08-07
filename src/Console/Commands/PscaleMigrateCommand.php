<?php

namespace X7media\LaravelPlanetscale\Console\Commands;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use X7media\LaravelPlanetscale\Connection;
use Illuminate\Http\Client\RequestException;
use X7media\LaravelPlanetscale\LaravelPlanetscale;
use Illuminate\Database\Console\Migrations\BaseCommand;

class PscaleMigrateCommand extends BaseCommand
{
    protected $signature = 'pscale:migrate {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--schema-path= : The path to a schema dump file}
        {--pretend : Dump the SQL queries that would be run}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step : Force the migrations to be run so they can be rolled back individually}';

    protected $description = 'Prepare and run laravel migrations against a planetscale database';

    protected bool $hasError = false;
    protected int $pollRate = 5; // in seconds

    /**
     * BaseCommand::getMigrationPaths() reads $this->migrator->paths() for
     * migration paths registered by packages (loadMigrationsFrom). Resolved
     * lazily in pendingMigrationNames().
     *
     * @var \Illuminate\Database\Migrations\Migrator|null
     */
    protected $migrator = null;

    public function __construct(protected LaravelPlanetscale $pscale)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->pollRate = max(0, (int) config('planetscale.poll_rate', $this->pollRate));

        $this->info('Laravel migration tool for Planetscale databases.');
        $this->newLine();

        if ($this->hasNoPendingMigrations() && $this->pscale->runMigrations()) {
            $this->info('There are no pending migrations needing to be ran.');
            return 0;
        }

        $productionBranch = config('planetscale.production_branch');

        if (Str::startsWith($productionBranch, 'ZAH-')) {
            $this->info('Development branch detected. Running migrations directly without deploy request.');
            return $this->runDirectMigration();
        }

        $this->branch();

        return ($this->hasError) ? 1 : 0;
    }

    public function error($string, $verbosity = null): void
    {
        parent::error($string, $verbosity);
        $this->hasError = true;
    }

    private function runDirectMigration(): int
    {
        if ($this->pscale->runMigrations()) {
            $this->line('Running Laravel migrations directly on branch...');
            if ($this->call('migrate', [
                '--database' => $this->option('database'),
                '--force' => $this->option('force'),
                '--path' => $this->option('path'),
                '--realpath' => $this->option('realpath'),
                '--schema-path' => $this->option('schema-path'),
                '--pretend' => $this->option('pretend'),
                '--seed' => $this->option('seed'),
                '--seeder' => $this->option('seeder'),
                '--step' => $this->option('step'),
            ]) > 0) {
                $this->error('An error occured while trying to complete the migration.');
                return 1;
            }

            $this->newLine();
            $this->info('Migrations successfully applied!');
            return 0;
        }

        $this->warn("Testing detected. Skip running `php artisan migrate`...");
        return 0;
    }

    private function branch()
    {
        $devBranch = $this->pscale->getDevelopmentBranch();
        $productionBranch = config('planetscale.production_branch');
        $connectionName = $this->option('database') ?: config('database.default');

        // Snapshot everything we need from PRODUCTION before the connection is
        // swapped to the development branch:
        //   - the pending migration names (to record on production afterwards),
        //   - the applied ledger rows (to backfill a data-less dev branch),
        //   - the connection config (to swap back for the ledger sync).
        $pendingMigrations = $this->pscale->runMigrations() ? $this->pendingMigrationNames() : [];
        $productionLedger = $this->pscale->runMigrations() ? $this->productionLedgerRows($connectionName) : [];
        $productionConfig = config("database.connections.{$connectionName}");

        $this->line('Creating development branch to run migrations on...');
        try {
            $this->pscale->ensureBranchExists($devBranch, $productionBranch);
        } catch (RequestException $e) {
            return $this->error('Unable to create or verify development branch.');
        }

        $this->line('Obtaining credentials to development branch...');
        try {
            $connection = $this->pscale->branchPassword($devBranch);
        } catch (RequestException $e) {
            return $this->error('Unable to obtain credentials for development branch.');
        }

        // Swapping DB connection to dev branch
        $this->line('Connecting application to development branch...');
        if (!$this->setDatabaseConnection($connection))
            return;

        if ($this->pscale->runMigrations()) {
            // PlanetScale branches copy the schema but NOT the data, and deploy
            // requests never carry data either — so the dev branch's `migrations`
            // ledger can be missing rows (or empty on a freshly created branch).
            // Backfill it from production so `migrate` below only runs the
            // migrations that are truly pending. Skipped in pretend mode, which
            // must not write anywhere (not even ledger rows on the dev branch).
            if (!$this->option('pretend')) {
                $this->backfillDevelopmentLedger($connectionName, $productionLedger);
            }

            $this->line('Running Laravel migrations on development branch...');
            if ($this->call('migrate', [
                '--database' => $this->option('database'),
                '--force' => $this->option('force'),
                '--path' => $this->option('path'),
                '--realpath' => $this->option('realpath'),
                '--schema-path' => $this->option('schema-path'),
                '--pretend' => $this->option('pretend'),
                '--seed' => $this->option('seed'),
                '--seeder' => $this->option('seeder'),
                '--step' => $this->option('step'),
            ]) > 0)
                return $this->error('An error occured while trying to complete the migration on the development branch.');
        } else {
            $this->warn("Testing detected. Skip running `php artisan migrate`...");
        }

        // Pretend runs must not change anything: no deploy request (merging one
        // would apply real schema changes), no ledger writes anywhere.
        if ($this->option('pretend')) {
            $this->newLine();
            $this->info('Pretend mode: skipping deploy request creation and ledger sync.');
            return;
        }

        // Reuse an open deploy request left behind by a previous crashed run
        // (PlanetScale allows only one open deploy request per branch), or
        // create a fresh one.
        $this->line('Creating deploy request from development branch...');
        try {
            $deploy_id = $this->pscale->openDeployRequestNumber($devBranch) ?? $this->pscale->deployRequest($devBranch);
        } catch (RequestException $e) {
            return $this->error('Unable to create the deploy request on Planetscale.');
        }

        // Wait for it to be deployable
        $this->line('Verifying deploy request is mergeable...');
        do {
            sleep($this->pollRate);
            $deployment_state = $this->pscale->deploymentState($deploy_id);
        } while ($deployment_state == 'pending');

        if ($deployment_state == 'no_changes') {
            // The development branch schema is identical to production. This is
            // the normal outcome when a previous run already merged the schema
            // but died before recording the ledger (e.g. back-to-back deploys).
            // Deploying a no-changes request is rejected by PlanetScale, so
            // close it and just record the ledger on production.
            $this->warn('Deploy request has no schema changes — production schema is already up to date.');

            if (!empty($pendingMigrations)) {
                $this->warn('If any of these migrations modify DATA rather than schema, those changes did NOT reach production (deploy requests carry schema only) and must be applied manually: ' . implode(', ', $pendingMigrations));
            }

            try {
                $this->pscale->closeDeployRequest($deploy_id);
            } catch (RequestException $e) {
                $this->warn('Unable to close the empty deploy request. Close it manually in the PlanetScale UI or the next run will reuse it.');
            }

            $this->syncProductionLedger($connectionName, $productionConfig, $pendingMigrations);
            if ($this->hasError) return;

            $this->newLine();
            $this->info('Migration ledger synced; production branch already up to date!');
            return;
        }

        $this->line('Applying changes back to production branch...');
        try {
            $this->pscale->completeDeploy($deploy_id);
        } catch (RequestException $e) {
            return $this->error('Unable to deploy the development branch to the production branch.');
        }

        //Check deployment status
        $this->line('Confirming deployment was successful...');
        do {
            sleep($this->pollRate);
            $deployment_state = $this->pscale->deploymentState($deploy_id);
        } while (!in_array($deployment_state, ['complete', 'complete_cancel', 'complete_error', 'complete_pending_revert']));

        if ($deployment_state == 'complete_cancel')
            return $this->error('The deployment was unexpectedly canceled.');

        if ($deployment_state == 'complete_error')
            return $this->error('An unexcepected error occured during the deployment.');

        if ($deployment_state == 'complete_pending_revert' && config('planetscale.skip_revert_period')) {
            $this->line('Skipping revert period to finalize deploy request...');
            try {
                $this->pscale->skipRevertPeriod($deploy_id);
            } catch (RequestException $e) {
                $this->warn('Unable to skip the revert period on the deploy request. The next deploy may be blocked until PlanetScale closes the revert window automatically.');
            }
        }

        // Deploy requests merge SCHEMA only: the `migrations` rows that
        // `migrate` inserted above live on the dev branch and never reach
        // production. Record them on production ourselves, otherwise every
        // subsequent boot re-detects the migrations as pending and spawns
        // empty deploy requests (production incident 2026-08-06).
        $this->syncProductionLedger($connectionName, $productionConfig, $pendingMigrations);
        if ($this->hasError) return;

        $this->newLine();
        $this->info('Migrations successfully applied to production branch!');
    }

    // adapted from 'spatie/laravel-multitenancy' (also MIT licensed) tenant database switching task,
    // source here: https://github.com/spatie/laravel-multitenancy/blob/928cb24a087d8a9f00a963936446cb30841aa86a/src/Tasks/SwitchTenantDatabaseTask.php#L25
    protected function setDatabaseConnection(Connection $connection): bool
    {
        $connectionName = $this->option('database') ?? config('database.default');

        if (is_null(config("database.connections.{$connectionName}"))) {
            $this->error("The database connection `{$connectionName}` is not a valid connection configured on this application.");
            return false;
        }

        // Check if the connection uses read/write separation
        $hasReadWriteSeparation = !is_null(config("database.connections.{$connectionName}.read")) ||
            !is_null(config("database.connections.{$connectionName}.write"));

        if ($hasReadWriteSeparation) {
            // Handle read/write separated connections
            config([
                "database.connections.{$connectionName}.read.host" => [$connection->host],
                "database.connections.{$connectionName}.write.host" => [$connection->host],
                "database.connections.{$connectionName}.read.database" => $connection->database,
                "database.connections.{$connectionName}.write.database" => $connection->database,
                "database.connections.{$connectionName}.read.username" => $connection->username,
                "database.connections.{$connectionName}.read.password" => $connection->password,
                "database.connections.{$connectionName}.write.username" => $connection->username,
                "database.connections.{$connectionName}.write.password" => $connection->password,
            ]);
        } else {
            // Handle standard connections
            config([
                "database.connections.{$connectionName}.host" => $connection->host,
                "database.connections.{$connectionName}.database" => $connection->database,
                "database.connections.{$connectionName}.username" => $connection->username,
                "database.connections.{$connectionName}.password" => $connection->password,
            ]);
        }

        app('db')->extend($connectionName, function ($config, $name) use ($connection, $hasReadWriteSeparation) {
            if ($hasReadWriteSeparation) {
                // Handle read/write separated connections
                $config['read']['host'] = $connection->host;
                $config['write']['host'] = $connection->host;
                $config['read']['database'] = $connection->database;
                $config['write']['database'] = $connection->database;
                $config['read']['username'] = $connection->username;
                $config['read']['password'] = $connection->password;
                $config['write']['username'] = $connection->username;
                $config['write']['password'] = $connection->password;
            } else {
                // Handle standard connections
                $config['host'] = $connection->host;
                $config['database'] = $connection->database;
                $config['username'] = $connection->username;
                $config['password'] = $connection->password;
            }

            return app('db.factory')->make($config, $name);
        });

        DB::purge($connectionName);

        // Octane will have an old `db` instance in the Model::$resolver.
        Model::setConnectionResolver(app('db'));

        return true;
    }

    /**
     * Point the connection back at the production branch after it was swapped
     * to the development branch. setDatabaseConnection() registered a resolver
     * bound to the dev-branch credentials, so it must be overridden (not just
     * purged) with one bound to the original config.
     */
    protected function restoreProductionConnection(string $connectionName, array $productionConfig): void
    {
        config(["database.connections.{$connectionName}" => $productionConfig]);

        app('db')->extend($connectionName, function ($config, $name) use ($productionConfig) {
            return app('db.factory')->make($productionConfig, $name);
        });

        DB::purge($connectionName);

        Model::setConnectionResolver(app('db'));
    }

    /**
     * The names of every migration file that has not been recorded as ran
     * on the current default (production) connection.
     */
    protected function pendingMigrationNames(): array
    {
        $migrator = $this->migrator ??= app('migrator');

        return $migrator->usingConnection($this->option('database'), function () use ($migrator) {
            $files = $migrator->getMigrationFiles($this->getMigrationPaths());
            $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            return collect($files)
                ->keys()
                ->reject(fn ($name) => in_array($name, $ran))
                ->values()
                ->all();
        });
    }

    /**
     * The migrations table name, honoring a non-default `database.migrations`
     * config (Laravel 11+ array form or the legacy string form).
     */
    protected function migrationTable(): string
    {
        $migrations = config('database.migrations', 'migrations');

        return is_array($migrations) ? ($migrations['table'] ?? 'migrations') : ($migrations ?: 'migrations');
    }

    /**
     * All ledger rows currently recorded on the production branch.
     */
    protected function productionLedgerRows(string $connectionName): array
    {
        try {
            return DB::connection($connectionName)
                ->table($this->migrationTable())
                ->orderBy('id')
                ->get(['migration', 'batch'])
                ->all();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Insert any production ledger rows missing from the development branch's
     * `migrations` table, so `migrate` on the dev branch only executes the
     * migrations that are actually pending.
     */
    protected function backfillDevelopmentLedger(string $connectionName, array $productionLedger): void
    {
        if (empty($productionLedger)) {
            return;
        }

        try {
            $connection = DB::connection($connectionName);
            $table = $this->migrationTable();

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                $this->call('migrate:install', ['--database' => $this->option('database')]);
            }

            $known = $connection->table($table)->pluck('migration')->all();

            $missing = collect($productionLedger)->reject(fn ($row) => in_array($row->migration, $known));

            foreach ($missing as $row) {
                $connection->table($table)->insert([
                    'migration' => $row->migration,
                    'batch' => $row->batch,
                ]);
            }

            if ($missing->isNotEmpty()) {
                $this->line("Backfilled {$missing->count()} migration ledger row(s) on the development branch.");
            }
        } catch (Exception $e) {
            $this->warn('Could not backfill the development branch migration ledger: ' . $e->getMessage());
        }
    }

    /**
     * Record the migrations that were just deployed in the PRODUCTION branch's
     * `migrations` table. Failing to do so leaves production believing they are
     * still pending, which poisons every subsequent run with empty deploy
     * requests. On failure the command errors out: the container will retry on
     * next boot, hit the `no_changes` path, and re-attempt this sync.
     */
    protected function syncProductionLedger(string $connectionName, array $productionConfig, array $migrations): void
    {
        if (empty($migrations)) {
            return;
        }

        $this->line('Recording applied migrations on the production branch...');

        try {
            $this->restoreProductionConnection($connectionName, $productionConfig);

            $connection = DB::connection($connectionName);
            $table = $this->migrationTable();

            $batch = ((int) $connection->table($table)->max('batch')) + 1;

            foreach ($migrations as $migration) {
                if (! $connection->table($table)->where('migration', $migration)->exists()) {
                    $connection->table($table)->insert([
                        'migration' => $migration,
                        'batch' => $batch,
                    ]);

                    // Mirror Laravel's --step contract: one batch per migration,
                    // so individual rollbacks group the same way as on dev.
                    if ($this->option('step')) {
                        $batch++;
                    }
                }
            }

            $this->line('Migration ledger updated on production (' . count($migrations) . ' migration(s)).');
        } catch (Exception $e) {
            $this->error('Schema was deployed but the migration ledger could not be recorded on production: ' . $e->getMessage());
        }
    }

    protected function hasNoPendingMigrations(): bool
    {
        return count($this->pendingMigrationNames()) === 0;
    }
}
