<?php

declare(strict_types=1);

use function Zarbinco\Nearby\Tools\Consumer\absolutePath;
use function Zarbinco\Nearby\Tools\Consumer\composerCommand;
use function Zarbinco\Nearby\Tools\Consumer\manifest;
use function Zarbinco\Nearby\Tools\Consumer\process;
use function Zarbinco\Nearby\Tools\Consumer\writeJson;

require __DIR__.'/consumer/Support.php';

try {
    $options = getopt('', ['help', 'output:', 'composer:']);

    if (isset($options['help'])) {
        echo "Usage: php tools/check-real-compatibility.php [--output=build/real-compatibility.json] [--composer=/path/composer.phar]\n";
        echo "Resolves real Packagist metadata in isolated fixtures; does not install dependencies or use the package's development dependencies.\n";
        exit(0);
    }

    $path = absolutePath($options['output'] ?? 'build/real-compatibility-'.gmdate('Ymd-His').'.json');

    if (!str_ends_with(strtolower($path), '.json') || file_exists($path)) {
        throw new RuntimeException('Choose a new .json output path; existing files are not overwritten.');
    }

    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create the verification output directory.');
    }

    $directory = dirname($path).'/real-composer-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $composer = composerCommand($options['composer'] ?? null);
    $report = [
        'completed' => false, 'php_runtime' => PHP_VERSION,
        'methodology' => 'Real Packagist metadata with a local path repository. Runtime package requirements only; no Testbench, ignored platform requirements, or disabled security blocking.',
        'cases' => [],
    ];
    $cases = [
        ['latest', '^13.0', '8.3.0', false],
        ['lowest', '^13.0', '8.3.0', true],
        ['exact_minimum', '13.0.0', '8.3.0', false],
        ['laravel12', '^12.0', '8.3.0', false],
        ['php82', '^13.0', '8.2.30', false],
    ];

    foreach ($cases as [$name, $framework, $platform, $preferLowest]) {
        $fixture = $directory.'/'.$name;
        mkdir($fixture);
        $requirements = manifest(dirname(__DIR__), $framework, $platform);

        if ($name === 'php82') {
            // Attribute rejection to Nearby's PHP floor rather than the fixture or framework.
            $requirements['require']['php'] = '^8.2';
            unset($requirements['require']['laravel/framework']);
        }

        writeJson($fixture.'/composer.json', $requirements);
        $command = [...$composer, '--no-interaction', '--no-ansi', '--no-plugins', '--no-scripts',
            'update', '--no-install', '--no-dev', '--no-progress'];

        if ($preferLowest) {
            $command = [...$command, '--prefer-lowest', '--prefer-stable'];
        }

        echo 'Resolving real metadata: ', $name, "...\n";
        $result = process($command, $fixture);
        $entry = ['framework_constraint' => $requirements['require']['laravel/framework'] ?? null,
            'root_php_constraint' => $requirements['require']['php'], 'platform_php' => $platform, 'exit_code' => $result['exit_code']];

        if ($result['exit_code'] === 0) {
            if (in_array($name, ['laravel12', 'php82'], true)) {
                throw new RuntimeException($name.' unexpectedly resolved.');
            }

            $lock = json_decode(file_get_contents($fixture.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
            $versions = array_column($lock['packages'], 'version', 'name');

            if (!isset($versions['laravel/framework'], $versions['zarbinco/laravel-nearby'])
                || isset($versions['illuminate/database']) || isset($versions['orchestra/testbench'])) {
                throw new RuntimeException('The resolved fixture must satisfy Illuminate through the real Laravel framework without Testbench.');
            }

            $audit = process([...$composer, '--no-interaction', '--no-ansi', '--no-plugins', 'audit', '--locked', '--format=json'], $fixture);

            if ($audit['exit_code'] !== 0) {
                throw new RuntimeException($name." dependency audit failed:\n".$audit['output']);
            }

            $entry += ['status' => 'resolved', 'framework' => $versions['laravel/framework'], 'packages' => $versions,
                'audit' => json_decode($audit['output'], true, flags: JSON_THROW_ON_ERROR)];
        } elseif ($name === 'exact_minimum' && str_contains($result['output'], 'security advisories')
            && str_contains($result['output'], 'laravel/framework') && str_contains($result['output'], '13.0.0')) {
            preg_match_all('/PKSA-[a-z0-9-]+/', $result['output'], $matches);
            $entry += ['status' => 'blocked_security', 'advisories' => array_values(array_unique($matches[0])),
                'limitation' => 'Exact Laravel 13.0.0 was not installed or executed because Composer security blocking rejected it.'];
        } elseif ($name === 'laravel12' && str_contains($result['output'], 'conflicts with laravel/framework')) {
            $entry['status'] = 'rejected';
        } elseif ($name === 'php82' && preg_match('~zarbinco/laravel-nearby\s+\S+\s+requires php \^8\.3~', $result['output']) === 1) {
            $entry += ['status' => 'rejected', 'nearby_php_requirement_verified' => true];
        } else {
            throw new RuntimeException($name." failed for an unexpected reason:\n".$result['output']);
        }

        $report['cases'][$name] = $entry;
        writeJson($path, $report);
        echo $name, ': ', $entry['status'], isset($entry['framework']) ? ' ('.$entry['framework'].')' : '', "\n";
    }

    $report['completed'] = true;
    writeJson($path, $report);
    echo 'Real Composer verification metadata: ', $path, "\n";

    if ($report['cases']['exact_minimum']['status'] === 'blocked_security') {
        echo "Exact Laravel 13.0.0 remains unverified; the recorded security block is a minimum-version verification limitation.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
