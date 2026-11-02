<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\Nearby\Bounds;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;
use Zarbinco\Nearby\Tests\Integration\Fixtures\BoundsObservedPdo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\BoundsObservedStatement;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ScopedBranch;

final class BoundsGroupingTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode')->sql_mode;
        self::assertContains('ONLY_FULL_GROUP_BY', explode(',', $mode));
    }

    #[DataProvider('chainingOrders')]
    public function test_explicit_grouped_projections_work_in_supported_chaining_orders(string $order, array $expected): void
    {
        $this->seedReportingBranches();
        $bounds = $this->bounds();
        $query = Branch::query();

        if ($order === 'bounds before select') {
            $query->withinBounds($bounds)->select('tenant_id')->selectRaw('COUNT(*) AS total')->groupBy('tenant_id');
        } elseif ($order === 'group before bounds') {
            $query->select('tenant_id')->selectRaw('COUNT(*) AS total')->groupBy('tenant_id')->withinBounds($bounds);
        } elseif ($order === 'having before bounds') {
            $query->select('tenant_id')->selectRaw('COUNT(*) AS total')->groupBy('tenant_id')
                ->havingRaw('COUNT(*) > ?', [1])->withinBounds($bounds);
        } elseif ($order === 'raw projection before bounds') {
            $query->selectRaw('tenant_id, COUNT(*) AS total')->withinBounds($bounds)->groupBy('tenant_id');
        } else {
            $query->select('tenant_id')->selectRaw('COUNT(*) AS total')->withinBounds($bounds)->groupBy('tenant_id');
        }

        $results = $query->orderBy('tenant_id')->get();

        self::assertSame($expected, $this->totals($results));
        foreach ($results as $result) {
            self::assertSame(['tenant_id', 'total'], array_keys($result->getAttributes()));
            self::assertNull($result->getKey());
            self::assertArrayNotHasKey('nearby_distance_meters', $result->toArray());
        }
    }

    public static function chainingOrders(): iterable
    {
        $all = [[1, 4], [2, 2], [3, 1]];
        yield 'select then bounds then group' => ['select before bounds', $all];
        yield 'select then group then bounds' => ['group before bounds', $all];
        yield 'bounds then replacement select then group' => ['bounds before select', $all];
        yield 'select then group and having then bounds' => ['having before bounds', [[1, 4], [2, 2]]];
        yield 'complete raw projection before bounds' => ['raw projection before bounds', $all];
    }

    #[DataProvider('geographicReports')]
    public function test_group_counts_respect_viewport_edges_signed_meridians_and_coordinate_validity(Bounds $bounds, array $expected): void
    {
        $this->seedReportingBranches();

        self::assertSame($expected, $this->totals($this->report($bounds)->orderBy('tenant_id')->get()));
    }

    public static function geographicReports(): iterable
    {
        yield 'ordinary viewport touching negative antimeridian' => [new Bounds(0, -180, 10, -170), [[1, 3], [2, 1]]];
        yield 'wrapped viewport with exact corners' => [new Bounds(0, 170, 10, -170), [[1, 4], [2, 2], [3, 1]]];
        yield 'ordinary viewport touching positive antimeridian' => [new Bounds(0, 170, 10, 180), [[1, 3], [2, 1], [3, 1]]];
        yield 'full world still excludes null and invalid values' => [new Bounds(-90, -180, 90, 180), [[1, 6], [2, 2], [3, 1]]];
        yield 'zero height retains inclusive boundary' => [new Bounds(0, -180, 0, 180), [[2, 1]]];
    }

    public function test_multiple_grouping_columns_keep_each_distinct_combination(): void
    {
        $this->seedReportingBranches();
        $results = Branch::query()->select('tenant_id', 'is_active')->selectRaw('COUNT(*) AS total')
            ->withinBounds($this->bounds())->groupBy('tenant_id', 'is_active')
            ->orderBy('tenant_id')->orderBy('is_active')->get();

        self::assertSame([[1, 0, 1], [1, 1, 3], [2, 1, 2], [3, 1, 1]], $results->map(
            static fn (Branch $row): array => [(int) $row->tenant_id, (int) $row->is_active, (int) $row->total],
        )->all());
    }

    public function test_having_select_where_and_order_bindings_remain_in_their_sql_clauses(): void
    {
        $this->seedReportingBranches();
        $query = Branch::query()->select('tenant_id')->selectRaw('? AS report_label, COUNT(*) AS total', ['Viewport report'])
            ->where('is_active', true)->withinBounds($this->bounds())->groupBy('tenant_id')
            ->havingRaw('COUNT(*) > ?', [1])->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [2]);
        $sql = $query->toSql();

        self::assertSame(substr_count($sql, '?'), count($query->getBindings()));
        self::assertSame(['Viewport report', true, '0', '10', '170', '180', '-180', '-170', 1, 2], $query->getBindings());
        self::assertStringNotContainsString('Viewport report', $sql);
        $results = $query->get();
        self::assertSame([[2, 2], [1, 3]], $this->totals($results));
        self::assertSame(['Viewport report', 'Viewport report'], $results->pluck('report_label')->all());
        $page = (clone $query)->paginate(1, ['*'], 'page', 2);
        self::assertSame(2, $page->total());
        self::assertSame([[1, 3]], $this->totals($page->getCollection()));
        self::assertSame(['Viewport report'], $page->getCollection()->pluck('report_label')->all());
    }

    public function test_aggregate_alias_ordering_happens_in_mysql(): void
    {
        $this->seedReportingBranches();

        self::assertSame([[3, 1], [2, 2], [1, 4]], $this->totals($this->report()->orderBy('total')->orderBy('tenant_id')->get()));
    }

    public function test_global_scopes_soft_deletes_and_additional_where_conditions_apply_before_grouping(): void
    {
        $this->seedReportingBranches();
        $this->branch('Deleted inside', 5, 175, ['tenant_id' => 1, 'deleted_at' => '2026-01-01 00:00:00']);
        $results = ScopedBranch::query()->select('tenant_id')->selectRaw('COUNT(*) AS total')
            ->where('is_active', true)->withinBounds($this->bounds())->groupBy('tenant_id')->get();

        self::assertSame([[1, 3]], $this->totals($results));
    }

    public function test_custom_decimal_columns_and_table_names_support_explicit_grouped_reports(): void
    {
        foreach ([['a', 'A', '5', '180'], ['b', 'A', '5', '-180'], ['c', 'B', '5', '175'], ['d', 'B', '5', '0'], ['e', 'A', null, '175'], ['f', 'A', '5', '500']] as [$code, $name, $latitude, $longitude]) {
            CustomBranch::create(['code' => $code, 'name' => $name, 'lat' => $latitude, 'lng' => $longitude]);
        }
        $results = CustomBranch::query()->select('name')->selectRaw('COUNT(*) AS total')
            ->groupBy('name')->withinBounds($this->bounds())->orderBy('name')->get();

        self::assertSame([['A', 2], ['B', 1]], $results->map(static fn (CustomBranch $row): array => [$row->name, (int) $row->total])->all());
    }

    public function test_existing_or_filters_do_not_escape_the_viewport_where_group(): void
    {
        $this->seedReportingBranches();
        $results = Branch::query()->select('tenant_id')->selectRaw('COUNT(*) AS total')
            ->where('is_active', true)->orWhere('name', 'Tenant 1 inactive')
            ->withinBounds($this->bounds())->groupBy('tenant_id')->orderBy('tenant_id')->get();

        self::assertSame([[1, 4], [2, 2], [3, 1]], $this->totals($results));
    }

    public function test_intersected_bounds_and_clones_keep_independent_grouped_filters(): void
    {
        $this->seedReportingBranches();
        $query = $this->report()->orderBy('tenant_id');
        $narrowed = (clone $query)->withinBounds(new Bounds(0, 176, 10, -176));

        self::assertSame([[1, 2], [3, 1]], $this->totals($narrowed->get()));
        self::assertSame([[1, 4], [2, 2], [3, 1]], $this->totals($query->get()));
        self::assertSame([[1, 4], [2, 2], [3, 1]], $this->totals($query->get()));
    }

    public function test_grouped_get_uses_one_query_without_haversine_or_extra_projection(): void
    {
        $this->seedReportingBranches();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $query = $this->report()->orderBy('tenant_id');
        self::assertSame([], DB::getQueryLog());
        $sql = $query->toSql();
        self::assertStringNotContainsString('.*', $sql);
        self::assertStringNotContainsString('ATAN2', strtoupper($sql));
        self::assertLessThan(strpos($sql, ' group by '), strpos($sql, ' where '));
        self::assertSame([[1, 4], [2, 2], [3, 1]], $this->totals($query->get()));
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_grouped_count_without_having_returns_the_first_group_scalar_not_group_totals(): void
    {
        $this->seedReportingBranches();
        $query = $this->report()->orderBy('tenant_id');

        self::assertSame(4, (clone $query)->count());
        self::assertCount(3, $query->get());
        self::assertSame([[1, 4], [2, 2], [3, 1]], $this->totals($query->get()));
    }

    public function test_grouped_count_with_having_counts_qualifying_groups_in_laravels_derived_query(): void
    {
        $this->seedReportingBranches();
        $query = $this->report()->havingRaw('COUNT(*) > ?', [1])->orderBy('tenant_id');

        self::assertSame(2, (clone $query)->count());
        self::assertSame([[1, 4], [2, 2]], $this->totals($query->get()));
    }

    public function test_grouped_pagination_reports_group_totals_and_correct_page_rows(): void
    {
        $this->seedReportingBranches();
        $query = $this->report()->orderBy('tenant_id');
        $page = (clone $query)->paginate(2, ['*'], 'page', 2);

        self::assertSame(3, $page->total());
        self::assertSame([[3, 1]], $this->totals($page->getCollection()));
        $havingPage = $query->havingRaw('COUNT(*) > ?', [1])->paginate(1, ['*'], 'page', 2);
        self::assertSame(2, $havingPage->total());
        self::assertSame([[2, 2]], $this->totals($havingPage->getCollection()));
    }

    public function test_grouped_pagination_uses_the_normal_two_queries_and_simple_pagination_uses_one(): void
    {
        $this->seedReportingBranches();
        $query = $this->report()->orderBy('tenant_id');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = (clone $query)->paginate(2, ['*'], 'page', 1);

        self::assertSame(3, $page->total());
        self::assertCount(2, DB::getQueryLog());
        DB::flushQueryLog();
        $simple = $query->simplePaginate(2, ['*'], 'page', 2);
        self::assertSame([[3, 1]], $this->totals($simple->getCollection()));
        self::assertFalse($simple->hasMorePages());
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_the_literal_readme_grouping_example_returns_the_documented_report_shape(): void
    {
        foreach ([
            ['Tenant 1 boundary A', 35.6, 51.2, 1, true],
            ['Tenant 1 boundary B', 35.8, 51.5, 1, true],
            ['Tenant 2 A', 35.7, 51.3, 2, true],
            ['Tenant 2 B', 35.71, 51.31, 2, true],
            ['Tenant 2 C', 35.72, 51.32, 2, true],
            ['Below HAVING threshold', 35.7, 51.3, 3, true],
            ['Outside', 35.9, 51.3, 1, true],
            ['Inactive', 35.7, 51.3, 1, false],
            ['Missing', null, 51.3, 2, true],
            ['Invalid', 35.7, 500.0, 2, true],
        ] as [$name, $latitude, $longitude, $tenant, $active]) {
            $this->branch($name, $latitude, $longitude, ['tenant_id' => $tenant, 'is_active' => $active]);
        }
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');
        self::assertSame(1, preg_match('/### Grouping Results Within a Viewport\R(.*?)(?=\R### |\z)/s', $readme, $section));
        self::assertSame(1, preg_match('/```php\R(.*?)```/s', $section[1], $example));
        $code = str_replace('use App\\Models\\Branch;', 'use Zarbinco\\Nearby\\Tests\\Integration\\Fixtures\\Branch;', $example[1], $replacements);
        self::assertSame(1, $replacements);
        $results = null;
        eval($code);

        self::assertSame([[1, 2], [2, 3]], $this->totals($results));
    }

    #[DataProvider('invalidProjections')]
    public function test_wildcard_grouped_projections_remain_invalid_under_only_full_group_by(bool $appendRaw): void
    {
        $this->seedReportingBranches();
        $query = Branch::query()->withinBounds($this->bounds())->groupBy('tenant_id');
        if ($appendRaw) {
            $query->selectRaw('tenant_id, COUNT(*) AS total');
        }

        try {
            $query->get();
            self::fail('Selecting non-grouped model columns must remain invalid in strict SQL mode.');
        } catch (QueryException $exception) {
            self::assertSame(1055, (int) $exception->errorInfo[1]);
            self::assertStringContainsString('only_full_group_by', strtolower($exception->getMessage()));
        }
    }

    public static function invalidProjections(): iterable
    {
        yield 'implicit model wildcard only' => [false];
        yield 'raw aggregate appended after implicit wildcard' => [true];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_grouped_get_preserves_connection_validation_before_sql(string $version): void
    {
        $pdo = $this->observedPdo();
        $pdo->reportedVersion = $version;
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            $this->report()->get();
        } finally {
            self::assertSame(0, $pdo->prepareAttempts);
            self::assertSame(0, $pdo->executeAttempts);
        }
    }

    #[DataProvider('unsupportedVersions')]
    public function test_grouped_pagination_retry_rejects_replacement_before_its_derived_count_statement(string $version): void
    {
        $this->seedReportingBranches();
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->report()->orderBy('tenant_id');
        self::assertCount(3, $query->get());
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, $version): Connection {
            $replacement = $this->observedPdo();
            // The PDO transport remains real MySQL 8.4; only version metadata is substituted.
            $replacement->reportedVersion = $version;

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $sessionId = (int) $initial->query('SELECT CONNECTION_ID()')->fetchColumn();
        self::assertGreaterThan(0, $sessionId);
        $this->observedPdo()->exec('KILL CONNECTION '.$sessionId);
        $rejected = false;

        try {
            (clone $query)->paginate(2, ['*'], 'page', 1);
        } catch (UnsupportedDatabaseException) {
            $rejected = true;
        }

        self::assertInstanceOf(BoundsObservedPdo::class, $replacement);
        $observation = sprintf('Replacement version_reads=%d, prepares=%d, executes=%d.', $replacement->serverVersionReads, $replacement->prepareAttempts, $replacement->executeAttempts);
        self::assertSame(0, $replacement->prepareAttempts, $observation);
        self::assertSame(0, $replacement->executeAttempts, $observation);
        self::assertGreaterThanOrEqual(1, $replacement->serverVersionReads);
        self::assertTrue($rejected);
    }

    #[DataProvider('supportedRetryRoutes')]
    public function test_supported_replacements_are_validated_before_grouped_count_and_data_queries(bool $write): void
    {
        $this->seedReportingBranches();
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->report()->orderBy('tenant_id');
        if ($write) {
            $query->useWritePdo();
        }
        self::assertCount(3, $query->get());
        $replacement = null;
        $reconnections = 0;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, &$reconnections): Connection {
            $reconnections++;
            $replacement = $this->observedPdo();

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $sessionId = (int) $initial->query('SELECT CONNECTION_ID()')->fetchColumn();
        self::assertGreaterThan(0, $sessionId);
        $this->observedPdo()->exec('KILL CONNECTION '.$sessionId);
        $page = (clone $query)->paginate(2, ['*'], 'page', 1);

        self::assertSame(3, $page->total());
        self::assertSame([[1, 4], [2, 2]], $this->totals($page->getCollection()));
        self::assertSame(1, $reconnections);
        self::assertInstanceOf(BoundsObservedPdo::class, $replacement);
        self::assertSame(['version', 'prepare', 'execute', 'prepare', 'execute'], $replacement->events);
        self::assertSame(1, $replacement->serverVersionReads);
    }

    public static function supportedRetryRoutes(): iterable
    {
        yield 'ordinary read query' => [false];
        yield 'write-selected data query' => [true];
    }

    public function test_grouped_pagination_in_a_transaction_uses_and_validates_the_write_pdo(): void
    {
        $this->seedReportingBranches();
        $connection = DB::connection();
        $read = $this->observedPdo();
        $read->reportedVersion = '8.0.42';
        $write = $this->observedPdo();
        $connection->setPdo($write)->setReadPdo($read);
        $connection->beginTransaction();

        try {
            $page = $this->report()->orderBy('tenant_id')->paginate(2, ['*'], 'page', 1);

            self::assertSame(3, $page->total());
            self::assertSame([[1, 4], [2, 2]], $this->totals($page->getCollection()));
            self::assertSame(0, $read->serverVersionReads);
            self::assertSame(0, $read->prepareAttempts);
            self::assertSame(1, $write->serverVersionReads);
            self::assertSame(2, $write->prepareAttempts);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_grouped_pagination_retains_laravels_read_count_and_write_selected_data_routing(): void
    {
        $this->seedReportingBranches();
        $connection = DB::connection();
        $read = $this->observedPdo();
        $write = $this->observedPdo();
        $connection->setPdo($write)->setReadPdo($read);
        $page = $this->report()->useWritePdo()->orderBy('tenant_id')->paginate(2, ['*'], 'page', 1);

        self::assertSame(3, $page->total());
        self::assertSame([[1, 4], [2, 2]], $this->totals($page->getCollection()));
        self::assertSame(1, $read->prepareAttempts);
        self::assertSame(1, $read->executeAttempts);
        self::assertSame(1, $read->serverVersionReads);
        self::assertSame(1, $write->prepareAttempts);
        self::assertSame(1, $write->executeAttempts);
        self::assertSame(1, $write->serverVersionReads);
    }

    public static function unsupportedVersions(): iterable
    {
        yield 'older MySQL metadata' => ['8.0.42'];
        yield 'MariaDB metadata' => ['11.4.3-MariaDB'];
    }

    private function bounds(): Bounds
    {
        return new Bounds(0, 170, 10, -170);
    }

    private function report(?Bounds $bounds = null): Builder
    {
        return Branch::query()->select('tenant_id')->selectRaw('COUNT(*) AS total')->withinBounds($bounds ?? $this->bounds())->groupBy('tenant_id');
    }

    private function totals(iterable $results): array
    {
        $totals = [];
        foreach ($results as $row) {
            $totals[] = [(int) $row->tenant_id, (int) $row->total];
        }

        return $totals;
    }

    private function seedReportingBranches(): void
    {
        $rows = [];
        foreach ([
            ['Tenant 1 east', 5, 180, 1, true],
            ['Tenant 1 west', 5, -180, 1, true],
            ['Tenant 1 interior', 5, 175, 1, true],
            ['Tenant 1 inactive', 5, -175, 1, false],
            ['Tenant 2 southwest corner', 0, 170, 2, true],
            ['Tenant 2 northeast corner', 10, -170, 2, true],
            ['Tenant 3 interior', 5, 179, 3, true],
            ['Outside north', 20, 175, 1, true],
            ['Outside longitude', 5, 0, 1, true],
            ['Missing latitude', null, 175, 2, true],
            ['Missing longitude', 5, null, 2, true],
            ['Invalid latitude', 91, 175, 2, true],
            ['Invalid longitude', 5, 500, 2, true],
        ] as [$name, $latitude, $longitude, $tenant, $active]) {
            $rows[] = ['name' => $name, 'latitude' => $latitude, 'longitude' => $longitude, 'tenant_id' => $tenant, 'is_active' => $active];
        }
        DB::table('nearby_test_branches')->insert($rows);
    }

    private function observedPdo(): BoundsObservedPdo
    {
        $config = config('database.connections.nearby_test_mysql');
        $pdo = new BoundsObservedPdo(
            'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [BoundsObservedStatement::class, [$pdo]]);

        return $pdo;
    }
}
