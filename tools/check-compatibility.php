<?php

declare(strict_types=1);

// Local package fixtures exercise Composer's solver without downloading Laravel.
$package = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$package['version'] = 'dev-main';
$package['type'] = 'metapackage';
unset($package['require-dev'], $package['autoload'], $package['autoload-dev'], $package['scripts']);

$composer = $argv[1] ?? getenv('COMPOSER_BINARY') ?: 'composer';
$command = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];
$command = [...$command, '--no-interaction', '--no-ansi', '--no-plugins', '--no-scripts',
    'update', '--dry-run', '--no-install', '--no-progress', '--no-audit'];
$directory = sys_get_temp_dir().'/nearby-composer-'.bin2hex(random_bytes(8));

if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create the Composer fixture directory.');
}

$cases = [
    ['PHP 8.2 is rejected', '8.2.0', null, null, false],
    ['Standalone PHP 8.3 is permitted', '8.3.0', null, null, true],
    ['Laravel 12 is rejected', '8.3.0', 'laravel/framework', '12.0.0', false],
    ['Laravel 13 is permitted', '8.3.0', 'laravel/framework', '13.0.0', true],
    ['Illuminate 12 components are rejected', '8.3.0', 'illuminate', '12.0.0', false],
    ['Illuminate 13 components are permitted', '8.3.0', 'illuminate', '13.0.0', true],
];

try {
    foreach ($cases as [$description, $php, $dependency, $version, $shouldSucceed]) {
        $packages = [$package];
        $require = [$package['name'] => 'dev-main'];

        if ($dependency === 'laravel/framework') {
            $replaces = array_fill_keys(array_filter(
                array_keys($package['conflict']),
                static fn (string $name): bool => str_starts_with($name, 'illuminate/'),
            ), 'self.version');
            $packages[] = ['name' => $dependency, 'version' => $version, 'type' => 'metapackage', 'replace' => $replaces];
            $require[$dependency] = $version;
        } elseif ($dependency === 'illuminate') {
            foreach (array_keys($package['conflict']) as $name) {
                if (str_starts_with($name, 'illuminate/')) {
                    $packages[] = ['name' => $name, 'version' => $version, 'type' => 'metapackage'];
                    $require[$name] = $version;
                }
            }
        }

        $fixture = [
            'name' => 'nearby/compatibility-check',
            'require' => $require,
            'repositories' => [
                ['type' => 'package', 'package' => $packages],
                ['packagist.org' => false],
            ],
            'config' => ['platform' => ['php' => $php], 'allow-plugins' => false],
        ];
        file_put_contents($directory.'/composer.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Composer. Pass its binary path as the first argument.');
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        if (($status === 0) !== $shouldSucceed) {
            throw new RuntimeException($description." failed:\n".$output);
        }

        if (!$shouldSucceed && !str_contains($output, $dependency === null ? 'php ^8.3' : 'conflicts with')) {
            throw new RuntimeException($description." failed for an unexpected reason:\n".$output);
        }

        echo $description, ".\n";
    }
} finally {
    unlink($directory.'/composer.json');
    rmdir($directory);
}
