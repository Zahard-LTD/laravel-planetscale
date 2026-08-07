<?php

return [
    'organization' => env('PLANETSCALE_ORGANIZATION'),

    'production_branch' => env('PLANETSCALE_PRODUCTION_BRANCH', 'main'),

    'development_branch' => env('PLANETSCALE_DEVELOPMENT_BRANCH'),

    'database' => env('DB_DATABASE'),

    /*
     * Whether to skip PlanetScale's revert window after a deploy request is
     * applied. When enabled (default), the package finalizes the deploy
     * request by calling the skip-revert endpoint as soon as the deployment
     * reaches `complete_pending_revert`. Disable this if you want to keep
     * the ability to revert the schema for the duration of the revert
     * window (typically 30 minutes) — but be aware that PlanetScale will
     * reject any subsequent deploy request merge until that window closes.
     */
    'skip_revert_period' => (bool) env('PLANETSCALE_SKIP_REVERT_PERIOD', true),

    /*
     * Seconds between polls of a deploy request's deployment state while
     * waiting for it to become mergeable / to complete. Overridable mainly
     * so the test suite can drop it to 0.
     */
    'poll_rate' => (int) env('PLANETSCALE_MIGRATE_POLL_RATE', 5),

    /*
     *   For security, when customizing this config,
     *   DO NOT use a hard-coded service token here.
     */
    'service_token' => [
        'id' => env('PLANETSCALE_SERVICE_TOKEN_ID'),
        'value' => env('PLANETSCALE_SERVICE_TOKEN'),
    ],
];
