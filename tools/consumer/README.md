# Testing a Laravel Consumer

These fixtures install the package into a small Laravel application with its own Composer dependencies. They use Laravel's standard application builder, Artisan, HTTP kernel, routing, model autoloading, and MySQL connection. Orchestra Testbench and the package's development dependencies are not installed in the consumer.

## Running the Application Checks

Create a dedicated, disposable MySQL 8.4 database whose name ends in `_consumer`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in your shell. The checks drop and recreate only their `nearby_consumer_branches`, `nearby_consumer_regions`, and `nearby_consumer_warehouses` fixture tables. Do not use an application database, and keep credentials outside tracked files.

```bash
php tools/consumer/run.php --run --framework=latest --directory=build/consumer-latest
php tools/consumer/run.php --run --framework=lowest --directory=build/consumer-lowest
```

Use a new or empty directory for each run. The application, vendor dependencies, lockfile, and verification metadata remain there for inspection and belong outside review archives. `build/` is ignored by Git.

`latest` resolves `laravel/framework:^13.0` normally. `lowest` first runs Composer's `--prefer-lowest --prefer-stable` without installing packages to identify the earliest framework currently permitted by the real solver and its security policy. It then pins only that framework version in the disposable application and performs a normal dependency update and installation for the current PHP interpreter. This tests the minimum framework with current compatible transitive dependencies, rather than loading the oldest possible version of every dependency. The initial framework selection is recorded in `minimum-framework-resolution.json`.

This is not a claim that every Laravel 13 version has been tested. An explicit `--framework=13.0.0` attempts that exact boundary and fails if Composer blocks it; the tool never disables security blocking or ignores platform requirements. Old transitive versions can emit deprecations on newer PHP interpreters even when their Composer constraints permit installation, which is why framework-minimum testing and an all-dependencies-lowest stress test are different checks.

The local checkout receives a `dev-main` version only in the consumer's path-repository options. No global stability setting or published package metadata is changed. Composer uses a symlink or Windows junction to the checkout, so current source changes remain visible to the disposable consumer.

Each application runs in separate PHP processes using its own `vendor/autoload.php`. The runner verifies Artisan boot, audits the installed dependency set, and exercises the standalone API, all documented query scopes, custom DECIMAL columns, string keys, relationships, tenant scopes, soft deletes, pagination, model saving, serialization, repeated HTTP JSON responses, and a real PDO reconnection. Assertions fail the command immediately, and fixture tables are removed in a `finally` block.

To run the same installed dependency set with another PHP interpreter, use `--verify-existing`:

```bash
php tools/consumer/run.php --run --verify-existing --directory=build/consumer-latest
```

This checks the actual runtime's platform requirements, audits dependencies, boots Artisan, and repeats the application checks without resolving or reinstalling packages. It writes a separate `verification-PHP_VERSION.json` file. Run checks against the same fixture database sequentially because each application recreates the same isolated fixture tables.

A lockfile resolved on one PHP version can contain dependencies with an upper PHP bound. If replay rejects that dependency set on a newer interpreter, create a new consumer under that interpreter with `--framework=lowest` and a new directory. Let Composer choose compatible dependencies rather than ignoring platform requirements. The package checkout's lockfile does not control dependency versions in these consumers.

## Checking Real Dependency Resolution

```bash
php tools/check-real-compatibility.php --output=build/real-compatibility.json
```

This command resolves real Packagist metadata without installing dependencies. It separately checks the latest and lowest currently resolvable Laravel 13 sets with a simulated PHP 8.3.0 platform, the exact Laravel 13.0.0 boundary, Laravel 12 rejection, and PHP 8.2 rejection. The PHP 8.2 fixture permits that version itself and requires a diagnostic naming Nearby's own PHP requirement, so a rejection caused only by the fixture or another dependency does not pass. Successful sets also undergo a lockfile audit. A simulated Composer platform verifies dependency constraints; runtime coverage comes from running the consumer and package tests on actual PHP versions.

If security advisories block Laravel 13.0.0, the report records `blocked_security` and explicitly leaves that exact version unverified. The command accepts only this identified limitation; unexpected dependency failures, an accepted unsupported version, or an audit failure return a failing exit code. A successful command therefore means the verification behaved as expected, not that a security-blocked version was installed successfully.

Both commands accept `--composer=/absolute/path/composer.phar` when Composer cannot be located automatically. Composer runs through the current PHP interpreter, which also lets these checks run under separate PHP 8.3, 8.4, and 8.5 installations.
