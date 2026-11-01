# Contributing to Laravel Nearby

Thank you for helping improve Laravel Nearby. Open an issue to discuss a substantial API change before preparing a pull request, and keep changes focused on a concrete use case.

## Development

Use PHP 8.3 or later within the PHP 8 series and Composer 2. Install dependencies and run the checks from the repository root:

```bash
composer install
composer validate --strict
composer test
composer test:compatibility
composer examples
```

The GitHub Actions configuration tests standalone calculations and Laravel/MySQL integration on PHP 8.3, 8.4, and 8.5, including real consumer installations. A dependency-resolution job shares a generated lockfile within that workflow run. The calculation core must continue to work through Composer autoloading alone, without Laravel bootstrapping, a database connection, or external services. The package requires Illuminate Database 13, and Laravel 13.0 is the minimum permitted framework version; do not add compatibility code for older versions.

Use strict internal types, explicit validation at public input boundaries, immutable value objects, and small classes with clear responsibilities. Match the existing formatting. Add meaningful regression tests for behavior changes, use independent numerical references, and choose floating-point tolerances that reflect the calculation being tested. Never compare spherical calculations to ellipsoidal reference values as if they were the same model.

## Database Tests

Eloquent tests require real MySQL 8.4 and the PDO MySQL extension. Create a dedicated, disposable test database and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in your shell. Keep credentials outside the repository. The suite creates, drops, or recreates `nearby_test_branches`, `nearby_test_custom_branches`, and `nearby_test_regions`; do not connect it to an application database.

```bash
composer test:integration
composer test:all
```

`composer test` remains independent of database availability. Do not substitute SQLite or mocked queries for MySQL integration coverage. Use small test-only schemas created inside the test environment; package migrations and consumer schema changes are unnecessary.

Keep the SQL calculation aligned with the existing PHP calculator and its authoritative Earth-radius constant. Test ordinary coordinates, poles, antimeridian crossings, very short arcs, and antipodal cases with realistic floating-point tolerances. Compare unrounded results, and test boundary inclusion without widening the radius. Preserve Eloquent filters, global scopes, and soft deletes when composing queries, and cover bindings, safe identifiers, deterministic ordering, and saving models with computed distances.

For changes to candidate selection, compare model IDs against the exact distance query as well as independent PHP calculations. Keep bounding ranges conservative and leave exact radius comparisons unchanged. Cover ordinary and proximity-loaded serialization, application visibility settings, and connection replacement when changing query preparation.

## Consumer Installation Checks

Run `composer test:compatibility:real` to verify real Composer resolution and audits in addition to the quick metadata fixtures. Use the [consumer testing guide](tools/consumer/README.md) for a separate Laravel application with runtime dependencies only and a dedicated database ending in `_consumer`. The fixture exercises Artisan, HTTP JSON responses, relationships, tenant filters, model saving, and database reconnection.

Test the latest and lowest currently resolvable Laravel 13 sets separately on the actual PHP runtimes. A development Testbench version can require a later framework release than the package declares, and this library's development lockfile does not control a consuming application's dependencies. A lowest-version lockfile resolved on one PHP version can contain a transitive package that rejects another PHP version; resolve that consumer independently rather than ignoring platform requirements. Keep Composer's security blocking enabled and report an exact minimum that cannot be installed as unverified.

## Performance Measurements

Use the optional utility described in [benchmarks/README.md](benchmarks/README.md) with a dedicated MySQL 8.4 development database. Compare deterministic datasets, warm up queries, take repeated measurements, and inspect execution plans before drawing conclusions. Keep generated results outside tracked source. Larger datasets and timing comparisons do not belong in routine CI, and tests must not fail because a busy runner is slightly slower.

## Writing Documentation

Write all public documentation in clear, natural English for developers using the library. Explain when a feature is useful, its inputs and units, its result, and relevant limitations. Include concise, executable PHP examples with the required imports and verify any stated numerical output. Document only working public APIs, keep comments focused on non-obvious decisions, and keep development progress reports and internal planning terminology out of public guides. Maintain one English documentation set rather than adding translated guides.

## Pull Requests

Explain the problem, the resulting behavior, and how you verified it. Update examples and documentation when the public API changes. Do not include dependencies, local environment files, caches, or generated review archives in a pull request.
