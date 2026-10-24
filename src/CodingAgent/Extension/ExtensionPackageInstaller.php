<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\DTO\ExtensionPackageInstallResultDTO;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
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
 *  - Reuses an already-required package without running Composer update/upgrade.
 */
final class ExtensionPackageInstaller
{
    private const COMPOSER_JSON = 'composer.json';
    private const VENDOR_AUTOLOAD = 'vendor/autoload.php';
    private const DEFAULT_TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        /** @var list<string> */
        private readonly array $composerCommand = ['composer'],
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
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
        $manifest = $this->readManifest($root);
        if (null === $manifest) {
            return false;
        }

        $require = $manifest['require'] ?? null;
        if (!\is_array($require) || !\array_key_exists($package, $require)) {
            return false;
        }

        return is_file($root.'/'.self::VENDOR_AUTOLOAD);
    }

    /**
     * Ensure a curated package is present in the selected layer root.
     *
     * Already-required packages with an existing vendor autoload are returned as
     * alreadyInstalled without invoking Composer. Failed installs leave settings
     * unchanged because this service never writes enablement or extension settings.
     *
     * @param array<string, mixed>|null $composerConfigOverride Optional composer.json fragment
     *                                                          merged only when creating a new
     *                                                          root (tests use path repositories).
     */
    public function ensureInstalled(
        SettingsLayerEnum $layer,
        string $cwd,
        string $package,
        string $version,
        ?array $composerConfigOverride = null,
    ): ExtensionPackageInstallResultDTO {
        $this->assertWritableLayer($layer);
        $package = $this->normalizePackageName($package);
        $version = trim($version);
        if ('' === $version) {
            throw new \InvalidArgumentException('Extension package version must be a non-empty string.');
        }

        $root = $this->rootFor($layer, $cwd);
        if ($this->isInstalled($layer, $cwd, $package)) {
            return new ExtensionPackageInstallResultDTO(
                layer: $layer,
                root: $root,
                package: $package,
                version: $version,
                alreadyInstalled: true,
            );
        }

        $this->ensureRoot($root, $composerConfigOverride);
        $this->runComposerRequire($root, $package, $version);

        if (!$this->isInstalled($layer, $cwd, $package)) {
            throw new ExtensionPackageInstallException(\sprintf('Composer finished without installing "%s" into %s.', $package, $root));
        }

        return new ExtensionPackageInstallResultDTO(
            layer: $layer,
            root: $root,
            package: $package,
            version: $version,
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

    /**
     * @param array<string, mixed>|null $composerConfigOverride
     */
    private function ensureRoot(string $root, ?array $composerConfigOverride): void
    {
        if (!is_dir($root) && !@mkdir($root, 0o755, true) && !is_dir($root)) {
            throw new ExtensionPackageInstallException(\sprintf('Failed to create extensions root "%s".', $root));
        }

        $manifestPath = $root.'/'.self::COMPOSER_JSON;
        if (!is_file($manifestPath)) {
            $manifest = $this->defaultManifest();
            if (null !== $composerConfigOverride) {
                $manifest = $this->mergeManifest($manifest, $composerConfigOverride);
            }
            $this->writeManifest($manifestPath, $manifest);

            return;
        }

        $manifest = $this->readManifest($root);
        if (null === $manifest) {
            throw new ExtensionPackageInstallException(\sprintf('Existing composer.json is unreadable: %s', $manifestPath));
        }

        $changed = false;
        if (($manifest['config']['prepend-autoloader'] ?? null) !== false) {
            $manifest['config'] = \is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
            $manifest['config']['prepend-autoloader'] = false;
            $changed = true;
        }
        if (!isset($manifest['config']['sort-packages'])) {
            $manifest['config']['sort-packages'] = true;
            $changed = true;
        }
        if ($changed) {
            $this->writeManifest($manifestPath, $manifest);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultManifest(): array
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
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function mergeManifest(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\is_array($value) && \is_array($base[$key] ?? null) && !$this->isList($value) && !$this->isList($base[$key])) {
                $base[$key] = $this->mergeManifest($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return [] === $value || array_is_list($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $root): ?array
    {
        $path = $root.'/'.self::COMPOSER_JSON;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
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

    private function runComposerRequire(string $root, string $package, string $version): void
    {
        $constraint = $package.':'.$version;
        if ([] === $this->composerCommand) {
            throw new ExtensionPackageInstallException('Composer command is empty.');
        }
        $process = new Process(
            [
                ...$this->composerCommand,
                'require',
                $constraint,
                '--no-interaction',
                '--no-ansi',
                '--working-dir='.$root,
            ],
            $root,
            null,
            null,
            $this->timeoutSeconds,
        );

        try {
            $process->mustRun();
        } catch (ProcessStartFailedException $e) {
            throw new ExtensionPackageInstallException(\sprintf('Failed to start Composer for "%s" in %s: %s', $package, $root, $e->getMessage()), 0, $e);
        } catch (ProcessFailedException $e) {
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());
            throw new ExtensionPackageInstallException(\sprintf('Composer require failed for "%s" in %s%s', $package, $root, '' !== $output ? ": \n".$output : '.'), 0, $e);
        }
    }
}
