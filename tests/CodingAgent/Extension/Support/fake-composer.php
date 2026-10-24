#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Offline Composer stand-in for ExtensionPackageInstaller tests.
 *
 * Supports:
 *  - composer require <package>:<version> --working-dir=...
 *  - optional fail marker file path via FAKE_COMPOSER_FAIL_MARKER
 *
 * Writes package into require, preserves unrelated manifest keys, ensures
 * config.prepend-autoloader=false, and creates vendor/autoload.php.
 */
$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script

$workingDir = getcwd() ?: '.';
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
    if ('require' === $arg) {
        $constraint = array_shift($argv);
        continue;
    }
}

if (!is_string($constraint) || !str_contains($constraint, ':')) {
    fwrite(\STDERR, "fake-composer: missing require constraint\n");
    exit(2);
}

if (is_string($failMarker) && '' !== $failMarker && is_file($failMarker)) {
    fwrite(\STDERR, "fake-composer: forced failure\n");
    exit(1);
}

[$package, $version] = explode(':', $constraint, 2);
$manifestPath = rtrim($workingDir, '/').'/composer.json';
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

$vendor = rtrim($workingDir, '/').'/vendor';
if (!is_dir($vendor) && !mkdir($vendor, 0755, true) && !is_dir($vendor)) {
    fwrite(\STDERR, "fake-composer: cannot create vendor\n");
    exit(1);
}

$autoload = $vendor.'/autoload.php';
$autoloadBody = <<<'PHP'
<?php
// fake composer autoload
spl_autoload_register(static function (string $class): void {});
PHP;
if (false === file_put_contents($autoload, $autoloadBody)) {
    fwrite(\STDERR, "fake-composer: cannot write vendor/autoload.php\n");
    exit(1);
}

$lockPath = rtrim($workingDir, '/').'/composer.lock';
if (!is_file($lockPath)) {
    file_put_contents($lockPath, "{\"packages\":[]}\n");
}

fwrite(\STDOUT, "fake-composer: required {$package}:{$version}\n");
exit(0);
