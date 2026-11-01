<?php

declare(strict_types=1);

use function Zarbinco\Nearby\Tools\Consumer\absolutePath;
use function Zarbinco\Nearby\Tools\Consumer\composerCommand;
use function Zarbinco\Nearby\Tools\Consumer\manifest;
use function Zarbinco\Nearby\Tools\Consumer\process;
use function Zarbinco\Nearby\Tools\Consumer\writeJson;

require __DIR__.'/Support.php';

try {
    $options = getopt('', ['run', 'help', 'verify-existing', 'framework:', 'directory:', 'composer:']);

    if (isset($options['help']) || !isset($options['run'])) {
        echo "Usage: php tools/consumer/run.php --run --framework=latest|lowest|13.0.0 --directory=build/consumer-latest [--composer=/path/composer.phar]\n";
        echo "Replay an installed fixture: php tools/consumer/run.php --run --verify-existing --directory=build/consumer-latest\n";
        echo "Installs a real runtime-only Laravel consumer, boots Artisan and the HTTP kernel, and checks proximity queries against a dedicated DB_DATABASE ending in _consumer.\n";
        exit(0);
    }

    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*_consumer\z/', getenv('DB_DATABASE') ?: '') !== 1
        || !(getenv('DB_HOST') ?: '') || !(getenv('DB_USERNAME') ?: '')) {
        throw new RuntimeException('Set DB_HOST, DB_USERNAME, and a dedicated DB_DATABASE ending in _consumer before running the consumer checks.');
    }

    $selection = $options['framework'] ?? 'latest';

    if (!in_array($selection, ['latest', 'lowest', '13.0.0'], true)) {
        throw new InvalidArgumentException('Framework must be latest, lowest, or 13.0.0.');
    }

    $directory = absolutePath($options['directory'] ?? 'build/consumer-'.$selection.'-'.bin2hex(random_bytes(4)));
    $existing = isset($options['verify-existing']);

    if ($existing && (!isset($options['directory']) || !is_file($directory.'/vendor/autoload.php')
        || !is_file($directory.'/verify.php') || !is_file($directory.'/composer.lock')
        || (json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['name'] ?? null) !== 'nearby/consumer-verification')) {
        throw new RuntimeException('An existing run must target a previously generated consumer fixture with installed dependencies.');
    }

    if (!$existing && file_exists($directory) && (!is_dir($directory) || (new FilesystemIterator($directory))->valid())) {
        throw new RuntimeException('Use a new or empty consumer directory; existing applications are never modified.');
    }

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the consumer directory.');
    }

    if (!$existing) {
        $fixture = __DIR__.'/fixture';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $destination = $directory.DIRECTORY_SEPARATOR.substr($file->getPathname(), strlen($fixture) + 1);

            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination))) {
                throw new RuntimeException('Unable to create a consumer fixture directory.');
            }

            if (!copy($file->getPathname(), $destination)) {
                throw new RuntimeException('Unable to copy a consumer fixture file.');
            }
        }

        foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $relative) {
            if (!is_dir($directory.'/'.$relative)) {
                mkdir($directory.'/'.$relative, 0777, true);
            }
        }

        writeJson($directory.'/composer.json', manifest(dirname(__DIR__, 2), $selection === '13.0.0' ? '13.0.0' : '^13.0'));
    }

    $composer = composerCommand($options['composer'] ?? null);
    $minimumFramework = null;

    if (!$existing) {
        $command = [...$composer, '--no-interaction', '--no-ansi', '--no-plugins', '--no-scripts', 'update', '--no-dev', '--prefer-dist', '--no-progress'];

        if ($selection === 'lowest') {
            echo 'Resolving the earliest currently permitted Laravel 13 framework using PHP ', PHP_VERSION, "...\n";
            $minimum = process([...$command, '--no-install', '--prefer-lowest', '--prefer-stable'], $directory);

            if ($minimum['exit_code'] !== 0) {
                throw new RuntimeException("Minimum framework dependency resolution failed:\n".$minimum['output']);
            }

            $minimumLock = json_decode(file_get_contents($directory.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
            $minimumVersions = array_column($minimumLock['packages'], 'version', 'name');
            $minimumFramework = $minimumVersions['laravel/framework'];
            $requirements = json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $requirements['require']['laravel/framework'] = ltrim($minimumFramework, 'v');
            writeJson($directory.'/composer.json', $requirements);
            writeJson($directory.'/minimum-framework-resolution.json', [
                'framework' => $minimumFramework, 'php' => PHP_VERSION,
                'method' => 'Real --prefer-lowest --prefer-stable resolution without installation; pin only the resolved framework for normal transitive dependency installation.',
            ]);
        }

        echo 'Installing the ', $selection, ' Laravel 13 consumer using PHP ', PHP_VERSION, "...\n";
        $installation = process($command, $directory);

        if ($installation['exit_code'] !== 0) {
            throw new RuntimeException("Consumer dependency installation failed:\n".$installation['output']);
        }
    } else {
        echo 'Verifying the installed Laravel consumer using PHP ', PHP_VERSION, "...\n";
    }

    $lock = json_decode(file_get_contents($directory.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $versions = array_column($lock['packages'], 'version', 'name');

    if (isset($versions['orchestra/testbench']) || isset($versions['phpunit/phpunit']) || isset($versions['illuminate/database'])) {
        throw new RuntimeException('The runtime consumer must use framework replacement and contain no package testing dependencies.');
    }

    $platform = process([...$composer, '--no-interaction', '--no-ansi', '--no-plugins', 'check-platform-reqs', '--no-dev'], $directory);

    if ($platform['exit_code'] !== 0) {
        throw new RuntimeException("The consumer dependency set does not support the actual PHP runtime:\n".$platform['output']);
    }

    $environment = getenv();
    $environment['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
    $environment['APP_ENV'] = 'testing';
    $environment['APP_DEBUG'] = 'false';
    $environment['CACHE_STORE'] = 'array';
    $environment['SESSION_DRIVER'] = 'array';
    $environment['LOG_CHANNEL'] = 'stderr';
    $artisan = process([PHP_BINARY, 'artisan', '--version'], $directory, $environment);

    if ($artisan['exit_code'] !== 0 || !str_contains($artisan['output'], ltrim($versions['laravel/framework'], 'v'))) {
        throw new RuntimeException("Artisan did not boot the installed framework:\n".$artisan['output']);
    }

    $audit = process([...$composer, '--no-interaction', '--no-ansi', '--no-plugins', 'audit', '--format=json'], $directory);

    if ($audit['exit_code'] !== 0) {
        throw new RuntimeException("Consumer dependency audit failed:\n".$audit['output']);
    }

    $verification = process([PHP_BINARY, 'verify.php', '--run'], $directory, $environment);

    if ($verification['exit_code'] !== 0) {
        throw new RuntimeException("Consumer application verification failed:\n".$verification['output']);
    }

    echo trim($artisan['output']), "\n", $verification['output'];
    $verificationPath = $directory.($existing ? '/verification-'.PHP_VERSION.'.json' : '/verification.json');
    writeJson($verificationPath, [
        'completed' => true, 'php' => PHP_VERSION, 'selection' => $existing ? 'existing' : $selection,
        'minimum_framework_resolution' => $minimumFramework,
        'framework' => $versions['laravel/framework'], 'runtime_packages' => $versions,
        'audit' => json_decode($audit['output'], true, flags: JSON_THROW_ON_ERROR),
        'smoke' => json_decode($verification['output'], true, flags: JSON_THROW_ON_ERROR),
    ]);
    echo 'Consumer verification metadata: ', $verificationPath, "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
