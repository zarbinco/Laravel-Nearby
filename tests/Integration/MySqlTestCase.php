<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\UnsafeBranch;

abstract class MySqlTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'nearby_test_mysql');
        $app['config']->set('database.connections.nearby_test_mysql', [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => getenv('DB_DATABASE') ?: 'nearby_test',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        self::assertMatchesRegularExpression('/^8\.4\./', $version, 'Integration tests require real MySQL 8.4.');

        UnsafeBranch::$latitudeColumn = 'latitude';
        UnsafeBranch::$longitudeColumn = 'longitude';

        Schema::dropIfExists('nearby_test_custom_branches');
        Schema::dropIfExists('nearby_test_branches');

        Schema::create('nearby_test_branches', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('tenant_id')->default(1);
            $table->softDeletes();
        });

        Schema::create('nearby_test_custom_branches', static function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->decimal('lat', 18, 12)->nullable();
            $table->decimal('lng', 18, 12)->nullable();
        });
    }

    protected function branch(string $name, ?float $latitude, ?float $longitude, array $attributes = []): Branch
    {
        return Branch::create([
            'name' => $name,
            // PDO binds floats as strings; seed the exact input rather than PHP's display precision.
            'latitude' => $latitude === null ? null : sprintf('%.17g', $latitude),
            'longitude' => $longitude === null ? null : sprintf('%.17g', $longitude),
            ...$attributes,
        ]);
    }
}
