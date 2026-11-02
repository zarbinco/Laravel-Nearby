<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class BoundsDocumentationTest extends MySqlTestCase
{
    #[DataProvider('documentedViewports')]
    public function test_documented_controller_keeps_the_original_viewport_when_following_pagination_links(array $viewport, array $longitudes): void
    {
        $controller = $this->documentedController();
        $this->app['router']->get('/nearby-docs/branches', $controller);
        $rows = [];
        for ($id = 1; $id <= 75; $id++) {
            $rows[] = ['id' => $id, 'name' => 'Branch '.$id, 'latitude' => '0', 'longitude' => (string) $longitudes[$id % count($longitudes)], 'is_active' => true];
        }
        $rows[] = ['id' => 76, 'name' => 'Outside', 'latitude' => '50', 'longitude' => '0', 'is_active' => true];
        $rows[] = ['id' => 77, 'name' => 'Inactive', 'latitude' => '0', 'longitude' => (string) $longitudes[0], 'is_active' => false];
        DB::table('nearby_test_branches')->insert($rows);

        $first = $this->getJson('/nearby-docs/branches?'.http_build_query($viewport))->assertOk();
        self::assertSame(range(1, 50), array_column($first->json('data'), 'id'));
        $nextUrl = $first->json('next_page_url');
        self::assertIsString($nextUrl);
        $second = $this->getJson($nextUrl);

        self::assertSame(200, $second->getStatusCode(), 'Following next_page_url '.$nextUrl.' returned '.$second->getStatusCode().'.');
        $this->assertViewportQuery($nextUrl, $viewport, '2');
        self::assertSame(range(51, 75), array_column($second->json('data'), 'id'));
        self::assertNull($second->json('next_page_url'));
        $previousUrl = $second->json('prev_page_url');
        self::assertIsString($previousUrl);
        $this->assertViewportQuery($previousUrl, $viewport, '1');
        $previous = $this->getJson($previousUrl)->assertOk();
        self::assertSame(range(1, 50), array_column($previous->json('data'), 'id'));
    }

    public static function documentedViewports(): iterable
    {
        yield 'ordinary viewport' => [['south' => '-1', 'west' => '-10', 'north' => '1', 'east' => '10'], [0.5]];
        yield 'unwrapped antimeridian viewport' => [['south' => '-1', 'west' => '175', 'north' => '1', 'east' => '185'], [179, -179]];
    }

    private function documentedController(): string
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');
        preg_match_all('/```php\R(.*?)```/s', $readme, $blocks);
        $controllers = array_values(array_filter($blocks[1], static fn (string $block): bool => str_contains($block, 'final class BranchViewportController')));
        self::assertCount(1, $controllers, 'The executable viewport controller example must remain unambiguous.');
        $class = 'Zarbinco\\Nearby\\Tests\\Integration\\Documentation\\BranchViewportController';

        if (!class_exists($class, false)) {
            $code = preg_replace('/\A<\?php\s*/', '', $controllers[0]);
            $code = str_replace(
                ['namespace App\\Http\\Controllers;', 'use App\\Models\\Branch;'],
                ['namespace Zarbinco\\Nearby\\Tests\\Integration\\Documentation;', 'use Zarbinco\\Nearby\\Tests\\Integration\\Fixtures\\Branch;'],
                $code,
                $replacements,
            );
            self::assertSame(2, $replacements);
            eval($code);
        }

        return $class;
    }

    private function assertViewportQuery(string $url, array $viewport, string $page): void
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach ($viewport as $name => $value) {
            self::assertSame($value, $query[$name] ?? null, 'Pagination must preserve the original '.$name.' input.');
        }
        self::assertSame($page, $query['page'] ?? null);
    }
}
