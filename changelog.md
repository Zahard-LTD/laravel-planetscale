# Changelog

All notable changes to `LaravelPlanetscale` will be documented in this file.

## Unreleased

### Added
- Automatically call PlanetScale's `skip-revert` endpoint after a deploy reaches `complete_pending_revert`, so consecutive deploys are not blocked by the previous deploy request's revert window. Controlled by the new `planetscale.skip_revert_period` config / `PLANETSCALE_SKIP_REVERT_PERIOD` env var, defaulting to `true`.

## Version 1.1

### Added
- Support for Laravel 10.x.

## Version 1.0

### Added
- `php artisan pscale:migrate` command to manage the brnaching and merging to deploy a schema change.
