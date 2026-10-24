<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\DTO\ExtensionPackageInstallResultDTO;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Installs curated Composer extension packages into project or user Hatfield roots.
 *
 * Scope:
 *  - Writes only under `<cwd>/.hatfield/extensions` or `~/.hatfield/extensions`.
 *  - Never touches the host application composer.json / composer.lock.
 *  - Never writes extensions.enabled or extensions.settings.
 *  - Preserves existing extension Composer repositories and unrelated require entries.
 *  - Forces config.prepend-autoloader=false so extension deps cannot shadow host libraries.
 *  - Pins config.autoloader-suffix per settings layer so project and user roots can both
 *    load when they install the same package set (identical content-hash class names collide).
 *  - Reuses an already-installed package without Composer update/upgrade.
 */
final class ExtensionPackageInstaller
{
    private const COMPOSER_JSON = 'composer.json';
    private const INSTALLED_JSON = 'vendor/composer/installed.json';
    private const VENDOR_AUTOLOAD = 'vendor/autoload.php';
    private const DEFAULT_TIMEOUT_SECONDS = 300.0;
    private const AUTOLOADER_SUFFIX_PROJECT = 'HatfieldExtProject';
    private const AUTOLOADER_SUFFIX_USER = 'HatfieldExtUser';

    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ExecutableFinder $executableFinder = new ExecutableFinder(),
    ) {
    }

    public function rootFor(SettingsLayerEnum $layer, string $cwd): string
    {
        return match ($layer) {
            SettingsLayerEnum::Project => rtrim($cwd, '/').'/.hatfield/extensions',
            SettingsLayerEnum::User => rtrim($this->pathResolver->getHomeDir(), '/').'/.hatfield/extensions',
            SettingsLayerEnum::Defaults => throw new \InvalidArgumentException('Built-in defaults are not an extension package install target.'),
        };
    }

    public function isInstalled(SettingsLayerEnum $layer, string $cwd, string $package): bool
    {
        $this->assertWritableLayer($layer);
        $package = $this->normalizePackageName($package);
        $root = $this->rootFor($layer, $cwd);

        return $this->packagePresentInRoot($root, $package);
    }

    /**
     * Ensure a curated package is present in the selected layer root.
     *
     * Already-installed packages are returned without Composer require/update.
     * Wrong prepend-autoloader config is still corrected and dump-autoload is
     * rerun so generated autoload keeps host-safe registration. Failed installs
     * leave settings unchanged because this service never writes enablement.
     */
    public function ensureInstalled(
        SettingsLayerEnum $layer,
        string $cwd,
        string $package,
        string $version,
    ): ExtensionPackageInstallResultDTO {
        $this->assertWritableLayer($layer);
        $package = $this->normalizePackageName($package);
        $version = trim($version);
        if ('' === $version) {
            throw new \InvalidArgumentException('Extension package version must be a non-empty string.');
        }

        $root = $this->rootFor($layer, $cwd);
        $this->ensureRoot($root, $layer);
        // Fail closed on malformed existing manifests before invoking Composer.
        $this->readManifest($root);

        if ($this->packagePresentInRoot($root, $package)) {
            $this->ensureSafeAutoload($root, $layer);

            return new ExtensionPackageInstallResultDTO(
                root: $root,
                alreadyInstalled: true,
            );
        }

        // Fix unsafe prepend before require so a failed/cancelled install cannot
        // leave generated autoload configured to shadow host libraries.
        $this->ensureSafeComposerConfig($root, $layer);
        $this->runComposer(['require', $package.':'.$version, '--no-interaction', '--no-ansi', '--working-dir='.$root], $root, $package);

        if (!$this->packagePresentInRoot($root, $package)) {
            throw new ExtensionPackageInstallException(\sprintf('Composer finished without installing "%s" into %s.', $package, $root));
        }

        $this->ensureSafeAutoload($root, $layer);

        return new ExtensionPackageInstallResultDTO(
            root: $root,
            alreadyInstalled: false,
        );
    }

    private function assertWritableLayer(SettingsLayerEnum $layer): void
    {
        if (SettingsLayerEnum::Defaults === $layer) {
            throw new \InvalidArgumentException('Built-in defaults are not an extension package install target.');
        }
    }

    private function normalizePackageName(string $package): string
    {
        $package = trim($package);
        if (1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#', $package)) {
            throw new \InvalidArgumentException(\sprintf('Invalid Composer package name "%s".', $package));
        }

        return $package;
    }

    private function ensureRoot(string $root, SettingsLayerEnum $layer): void
    {
        try {
            $this->filesystem->mkdir($root);
        } catch (\Throwable $e) {
            throw new ExtensionPackageInstallException(\sprintf('Failed to create extensions root "%s": %s', $root, $e->getMessage()), 0, $e);
        }

        $manifestPath = $root.'/'.self::COMPOSER_JSON;
        if (!is_file($manifestPath)) {
            $this->writeManifest($manifestPath, $this->defaultManifest($layer));
        }
    }

    /**
     * Ensure host-safe Composer autoload config and regenerate vendor autoload when needed.
     */
    private function ensureSafeAutoload(string $root, SettingsLayerEnum $layer): void
    {
        $changed = $this->ensureSafeComposerConfig($root, $layer);
        if ($changed || !is_file($root.'/'.self::VENDOR_AUTOLOAD)) {
            $this->runComposer(['dump-autoload', '--no-interaction', '--no-ansi', '--working-dir='.$root], $root);
        }
    }

    /**
     * Write host-safe Composer config without regenerating autoload.
     *
     * @return bool true when composer.json changed
     */
    private function ensureSafeComposerConfig(string $root, SettingsLayerEnum $layer): bool
    {
        $manifestPath = $root.'/'.self::COMPOSER_JSON;
        if (!is_file($manifestPath)) {
            return false;
        }

        $manifest = $this->readManifest($root);
        $config = \is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $changed = false;
        if (($config['prepend-autoloader'] ?? null) !== false) {
            $config['prepend-autoloader'] = false;
            $changed = true;
        }
        if (!isset($config['sort-packages'])) {
            $config['sort-packages'] = true;
            $changed = true;
        }
        $suffix = $this->autoloaderSuffixFor($layer);
        if (($config['autoloader-suffix'] ?? null) !== $suffix) {
            $config['autoloader-suffix'] = $suffix;
            $changed = true;
        }
        if ($changed) {
            $manifest['config'] = $config;
            $this->writeManifest($manifestPath, $manifest);
        }

        return $changed;
    }

    private function autoloaderSuffixFor(SettingsLayerEnum $layer): string
    {
        return match ($layer) {
            SettingsLayerEnum::Project => self::AUTOLOADER_SUFFIX_PROJECT,
            SettingsLayerEnum::User => self::AUTOLOADER_SUFFIX_USER,
            SettingsLayerEnum::Defaults => throw new \InvalidArgumentException('Built-in defaults are not an extension package install target.'),
        };
    }

    private function packagePresentInRoot(string $root, string $package): bool
    {
        if (!is_file($root.'/'.self::VENDOR_AUTOLOAD)) {
            return false;
        }

        $installedPath = $root.'/'.self::INSTALLED_JSON;
        if (!is_file($installedPath) || !is_readable($installedPath)) {
            return false;
        }

        $content = file_get_contents($installedPath);
        if (false === $content || '' === trim($content)) {
            return false;
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtensionPackageInstallException(\sprintf('Invalid Composer installed.json at %s: %s', $installedPath, $e->getMessage()), 0, $e);
        }

        if (!\is_array($decoded) || !\array_key_exists('packages', $decoded) || !\is_array($decoded['packages'])) {
            return false;
        }

        $packages = $decoded['packages'];

        foreach ($packages as $entry) {
            if (\is_array($entry) && ($entry['name'] ?? null) === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultManifest(SettingsLayerEnum $layer): array
    {
        return [
            'name' => 'ineersa/hatfield-extensions',
            'description' => 'Composer root for Hatfield extensions',
            'type' => 'project',
            'license' => 'MIT',
            'require' => [
                'php' => '>=8.3',
            ],
            'config' => [
                'sort-packages' => true,
                'prepend-autoloader' => false,
                'autoloader-suffix' => $this->autoloaderSuffixFor($layer),
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $root): array
    {
        $path = $root.'/'.self::COMPOSER_JSON;
        if (!is_file($path) || !is_readable($path)) {
            throw new ExtensionPackageInstallException(\sprintf('composer.json is missing or unreadable: %s', $path));
        }

        $content = file_get_contents($path);
        if (false === $content || '' === trim($content)) {
            throw new ExtensionPackageInstallException(\sprintf('composer.json is empty: %s', $path));
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtensionPackageInstallException(\sprintf('Invalid composer.json at %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new ExtensionPackageInstallException(\sprintf('composer.json must decode to an object: %s', $path));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        try {
            $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        } catch (\JsonException $e) {
            throw new ExtensionPackageInstallException('Failed to encode composer.json: '.$e->getMessage(), 0, $e);
        }

        try {
            AtomicFileWriter::write($path, $json);
        } catch (\Throwable $e) {
            throw new ExtensionPackageInstallException(\sprintf('Failed to write composer.json at %s: %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function runComposer(array $arguments, string $root, ?string $package = null): void
    {
        $composer = $this->executableFinder->find('composer');
        if (null === $composer) {
            throw new ExtensionPackageInstallException('Composer executable was not found on PATH.');
        }

        $process = new Process([$composer, ...$arguments], $root, null, null, self::DEFAULT_TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessStartFailedException $e) {
            throw new ExtensionPackageInstallException(null !== $package ? \sprintf('Failed to start Composer for "%s" in %s: %s', $package, $root, $e->getMessage()) : \sprintf('Failed to start Composer in %s: %s', $root, $e->getMessage()), 0, $e);
        } catch (ProcessFailedException $e) {
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());
            throw new ExtensionPackageInstallException(null !== $package ? \sprintf('Composer require failed for "%s" in %s%s', $package, $root, '' !== $output ? ": \n".$output : '.') : \sprintf('Composer command failed in %s%s', $root, '' !== $output ? ": \n".$output : '.'), 0, $e);
        }
    }
}
