<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageUninstallException;
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
                if (isset($packages[$package['name']])) {
                    throw new ExtensionPackageInstallException('Installed Composer metadata contains duplicate package names.');
                }
                $packages[$package['name']] = $package;
            }
        }

        return $packages;
    }

    public function uninstall(SettingsLayerEnum $layer, string $cwd, string $package): void
    {
        $root = $this->rootFor($layer, $cwd);
        $other = $this->rootFor(SettingsLayerEnum::User === $layer ? SettingsLayerEnum::Project : SettingsLayerEnum::User, $cwd);
        $parent = realpath(\dirname($root, 2));
        if (false === $parent || realpath($root) !== $parent.'/.hatfield/extensions' || realpath($root) === realpath($other)) {
            throw new ExtensionPackageUninstallException('The extension installation is not exclusive to the selected scope.');
        }
        foreach (['composer.json', 'composer.lock', 'vendor', 'vendor/composer', 'vendor/bin', 'vendor/composer/installed.json', 'vendor/composer/installed.php'] as $path) {
            $target = $root.'/'.$path;
            if ((file_exists($target) || is_link($target)) && realpath($target) !== $parent.'/.hatfield/extensions/'.$path) {
                throw new ExtensionPackageUninstallException('A Composer path redirects outside the selected installation.');
            }
        }
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        if (!isset($manifest['require'][$package]) || isset($manifest['require-dev'][$package]) || !isset($this->packages($layer, $cwd)[$package])) {
            throw new ExtensionPackageUninstallException('Only a directly installed runtime requirement can be removed.');
        }
        $composer = (new ExecutableFinder())->find('composer');
        if (null === $composer) {
            throw new ExtensionPackageUninstallException('Composer executable was not found on PATH.');
        }
        $process = new Process(
            [$composer, 'remove', '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', '--minimal-changes', '--', $package],
            $root,
            ExtensionUpdateService::environment($root),
            timeout: 300,
        );
        $process->mustRun();
        if (isset($this->packages($layer, $cwd)[$package])) {
            throw new ExtensionPackageUninstallException('The requirement was removed but Composer still lists the package; check whether another package requires it.');
        }
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
        $suffix = 'HatfieldExt'.hash('sha256', (string) realpath($root));
        $generated = $root.'/vendor/composer/autoload_real.php';
        if ($installed && false === ($manifest['config']['prepend-autoloader'] ?? null)
            && $suffix === ($manifest['config']['autoloader-suffix'] ?? null)
            && is_file($root.'/vendor/autoload.php')
            && is_file($generated) && str_contains(file_get_contents($generated), 'class ComposerAutoloaderInit'.$suffix)
        ) {
            return;
        }
        $manifest['config']['prepend-autoloader'] = false;
        // Physical roots, not settings layers, identify generated Composer classes:
        // HOME may also be a project, and symlinked scope aliases must stay identical.
        $manifest['config']['autoloader-suffix'] = $suffix;
        $this->filesystem->dumpFile($manifestPath, json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        $composer = (new ExecutableFinder())->find('composer');
        if (null === $composer) {
            throw new ExtensionPackageInstallException('Composer executable was not found on PATH.');
        }
        $arguments = $installed ? ['dump-autoload'] : ['require', $package.':*'];
        $process = new Process([$composer, ...$arguments, '--no-interaction', '--no-ansi'], $root, timeout: 300);
        $process->mustRun();
        if (!isset($this->packages($layer, $cwd)[$package])) {
            throw new ExtensionPackageInstallException('Composer did not install '.$package);
        }
    }
}
