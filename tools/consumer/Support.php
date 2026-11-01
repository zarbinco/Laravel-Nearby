<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tools\Consumer;

use RuntimeException;

/** @return list<string> */
function composerCommand(?string $requested = null): array
{
    $candidate = $requested ?: (getenv('COMPOSER_BINARY') ?: null);

    if ($candidate === null) {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            foreach (['composer.phar', 'composer', 'composer.exe'] as $name) {
                if (is_file($directory.DIRECTORY_SEPARATOR.$name)) {
                    $candidate = $directory.DIRECTORY_SEPARATOR.$name;
                    break 2;
                }
            }
        }
    }

    if ($candidate === null || !is_file($candidate)) {
        throw new RuntimeException('Composer was not found. Pass --composer=/absolute/path/composer.phar.');
    }

    if (str_ends_with(strtolower($candidate), '.bat') || str_ends_with(strtolower($candidate), '.exe')) {
        $candidate = dirname($candidate).'/composer.phar';
    }

    if (!is_file($candidate)) {
        throw new RuntimeException('Use --composer=/absolute/path/composer.phar so Composer runs through the current PHP interpreter.');
    }

    return [PHP_BINARY, $candidate];
}

/** @return array{exit_code:int,output:string} */
function process(array $command, string $directory, ?array $environment = null): array
{
    $environment ??= getenv();
    $environment['COMPOSER_ROOT_VERSION'] = 'dev-main';
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory, $environment);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the requested verification process.');
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit_code' => proc_close($process), 'output' => $output];
}

function writeJson(string $path, array $value): void
{
    if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Unable to write verification metadata.');
    }
}

function absolutePath(string $path): string
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1 ? $path : getcwd().DIRECTORY_SEPARATOR.$path;
}

function manifest(string $packageDirectory, string $framework, ?string $platform = null): array
{
    $manifest = [
        'name' => 'nearby/consumer-verification',
        'type' => 'project',
        'license' => 'MIT',
        'require' => ['php' => '^8.3', 'laravel/framework' => $framework, 'zarbinco/laravel-nearby' => 'dev-main'],
        'repositories' => [[
            'type' => 'path', 'url' => str_replace('\\', '/', $packageDirectory),
            // The unpublished checkout receives a version only inside this fixture.
            'options' => ['symlink' => true, 'versions' => ['zarbinco/laravel-nearby' => 'dev-main']],
        ]],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'config' => ['allow-plugins' => false, 'sort-packages' => true],
    ];

    if ($platform !== null) {
        $manifest['config']['platform'] = ['php' => $platform];
    }

    return $manifest;
}
