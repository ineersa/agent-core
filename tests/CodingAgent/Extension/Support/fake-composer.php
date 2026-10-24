#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Offline Composer stand-in for ExtensionPackageInstaller tests.
 *
 * Supports:
 *  - composer require <package>:<version> --working-dir=...
 *  - composer dump-autoload --working-dir=...
 *  - optional fail marker file path via FAKE_COMPOSER_FAIL_MARKER
 *
 * Writes package into require + vendor/composer/installed.json, preserves
 * unrelated manifest keys, ensures config.prepend-autoloader=false, and
 * creates vendor/autoload.php.
 */
$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script

$workingDir = getcwd() ?: '.';
$command = null;
$constraint = null;
$failMarker = getenv('FAKE_COMPOSER_FAIL_MARKER');

while ([] !== $argv) {
    $arg = array_shift($argv);
    if (str_starts_with($arg, '--working-dir=')) {
        $workingDir = substr($arg, strlen('--working-dir='));
        continue;
    }
    if ('--working-dir' === $arg) {
        $workingDir = (string) array_shift($argv);
        continue;
    }
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (null === $command) {
        $command = $arg;
        continue;
    }
    if ('require' === $command && null === $constraint) {
        $constraint = $arg;
    }
}

if (is_string($failMarker) && '' !== $failMarker && is_file($failMarker)) {
    fwrite(\STDERR, "fake-composer: forced failure\n");
    exit(1);
}

$workingDir = rtrim($workingDir, '/');
$manifestPath = $workingDir.'/composer.json';
$vendor = $workingDir.'/vendor';
$composerDir = $vendor.'/composer';
$autoload = $vendor.'/autoload.php';
$installedPath = $composerDir.'/installed.json';

if ('dump-autoload' === $command) {
    if (!is_dir($composerDir) && !mkdir($composerDir, 0755, true) && !is_dir($composerDir)) {
        fwrite(\STDERR, "fake-composer: cannot create vendor/composer\n");
        exit(1);
    }
    $body = <<<'PHP'
<?php
// fake composer autoload (dump-autoload)
spl_autoload_register(static function (string $class): void {}, prepend: false);
PHP;
    if (false === file_put_contents($autoload, $body)) {
        fwrite(\STDERR, "fake-composer: cannot write vendor/autoload.php\n");
        exit(1);
    }
    fwrite(\STDOUT, "fake-composer: dump-autoload\n");
    exit(0);
}

if ('require' !== $command) {
    fwrite(\STDERR, "fake-composer: unsupported command\n");
    exit(2);
}

if (!is_string($constraint) || !str_contains($constraint, ':')) {
    fwrite(\STDERR, "fake-composer: missing require constraint\n");
    exit(2);
}

if (!is_file($manifestPath)) {
    fwrite(\STDERR, "fake-composer: missing composer.json in {$workingDir}\n");
    exit(1);
}

$raw = file_get_contents($manifestPath);
if (false === $raw) {
    fwrite(\STDERR, "fake-composer: cannot read composer.json\n");
    exit(1);
}

try {
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(\STDERR, 'fake-composer: invalid composer.json: '.$e->getMessage()."\n");
    exit(1);
}

[$package, $version] = explode(':', $constraint, 2);
if (!isset($manifest['require']) || !is_array($manifest['require'])) {
    $manifest['require'] = [];
}
$manifest['require'][$package] = $version;
if (!isset($manifest['config']) || !is_array($manifest['config'])) {
    $manifest['config'] = [];
}
$manifest['config']['prepend-autoloader'] = false;
$manifest['config']['sort-packages'] = true;

$encoded = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
if (false === file_put_contents($manifestPath, $encoded)) {
    fwrite(\STDERR, "fake-composer: cannot write composer.json\n");
    exit(1);
}

if (!is_dir($composerDir) && !mkdir($composerDir, 0755, true) && !is_dir($composerDir)) {
    fwrite(\STDERR, "fake-composer: cannot create vendor/composer\n");
    exit(1);
}

$packages = [];
if (is_file($installedPath)) {
    try {
        $existing = json_decode((string) file_get_contents($installedPath), true, 512, \JSON_THROW_ON_ERROR);
        if (is_array($existing) && array_key_exists('packages', $existing) && is_array($existing['packages'])) {
            $packages = $existing['packages'];
        } elseif (is_array($existing) && array_is_list($existing)) {
            $packages = $existing;
        }
    } catch (JsonException) {
        $packages = [];
    }
}

$found = false;
foreach ($packages as &$entry) {
    if (is_array($entry) && ($entry['name'] ?? null) === $package) {
        $entry['version'] = $version;
        $found = true;
        break;
    }
}
unset($entry);
if (!$found) {
    $packages[] = [
        'name' => $package,
        'version' => $version,
        'version_normalized' => $version,
        'install-path' => '../'.str_replace('/', '/', $package),
    ];
}

$installed = [
    'packages' => array_values($packages),
    'dev' => true,
    'dev-package-names' => [],
];
if (false === file_put_contents($installedPath, json_encode($installed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n")) {
    fwrite(\STDERR, "fake-composer: cannot write installed.json\n");
    exit(1);
}

$autoloadBody = <<<'PHP'
<?php
// fake composer autoload
spl_autoload_register(static function (string $class): void {}, prepend: false);
PHP;
if (false === file_put_contents($autoload, $autoloadBody)) {
    fwrite(\STDERR, "fake-composer: cannot write vendor/autoload.php\n");
    exit(1);
}

$lockPath = $workingDir.'/composer.lock';
if (!is_file($lockPath)) {
    file_put_contents($lockPath, "{\"packages\":[]}\n");
}

fwrite(\STDOUT, "fake-composer: required {$package}:{$version}\n");
exit(0);
