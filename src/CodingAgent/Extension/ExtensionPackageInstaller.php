<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class ExtensionPackageInstaller
{
    public function __construct(private SettingsPathResolver $paths, private Filesystem $filesystem)
    {
    }

    public function rootFor(SettingsLayerEnum $layer, string $cwd): string
    {
        return match ($layer) {
            SettingsLayerEnum::User => $this->paths->getHomeDir().'/.hatfield/extensions',
            SettingsLayerEnum::Project => $cwd.'/.hatfield/extensions',
            SettingsLayerEnum::Defaults => throw new ExtensionPackageInstallException('Built-in defaults are not an installation target.'),
        };
    }

    /** @return array<string, array<string, mixed>> */
    public function packages(SettingsLayerEnum $layer, string $cwd): array
    {
        $path = $this->rootFor($layer, $cwd).'/vendor/composer/installed.json';
        if (!is_file($path)) {
            return [];
        }
        $installed = json_decode(file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($installed) || !\is_array($installed['packages'] ?? null)) {
            throw new ExtensionPackageInstallException('Expected Composer 2 installed.json at '.$path);
        }
        $packages = [];
        foreach ($installed['packages'] as $package) {
            if (\is_array($package) && \is_string($package['name'] ?? null)) {
                $packages[$package['name']] = $package;
            }
        }

        return $packages;
    }

    public function install(SettingsLayerEnum $layer, string $cwd, string $package): void
    {
        $root = $this->rootFor($layer, $cwd);
        $this->filesystem->mkdir($root);
        $manifestPath = $root.'/composer.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, flags: \JSON_THROW_ON_ERROR) : ['name' => 'hatfield/extensions', 'type' => 'project'];
        if (!\is_array($manifest)) {
            throw new ExtensionPackageInstallException('Expected a Composer manifest object at '.$manifestPath);
        }
        $installed = isset($this->packages($layer, $cwd)[$package]);
        $suffix = 'HatfieldExt'.$layer->name;
        $generated = $root.'/vendor/composer/autoload_real.php';
        if ($installed && false === ($manifest['config']['prepend-autoloader'] ?? null)
            && $suffix === ($manifest['config']['autoloader-suffix'] ?? null)
            && is_file($root.'/vendor/autoload.php')
            && is_file($generated) && str_contains(file_get_contents($generated), 'class ComposerAutoloaderInit'.$suffix)
        ) {
            return;
        }
        $manifest['config']['prepend-autoloader'] = false;
        // Separate generated Composer init classes even for identical project/user package sets.
        $manifest['config']['autoloader-suffix'] = $suffix;
        $this->filesystem->dumpFile($manifestPath, json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        $composer = (new ExecutableFinder())->find('composer');
        if (null === $composer) {
            throw new ExtensionPackageInstallException('Composer executable was not found on PATH.');
        }
        $arguments = $installed ? ['dump-autoload'] : ['require', $package];
        $process = new Process([$composer, ...$arguments, '--no-interaction', '--no-ansi'], $root, timeout: 300);
        $process->mustRun();
        if (!isset($this->packages($layer, $cwd)[$package])) {
            throw new ExtensionPackageInstallException('Composer did not install '.$package);
        }
    }
}
