<?php

declare(strict_types=1);

namespace Ineersa\Tests\Support;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Offline real-Composer fixtures for extension dual-root autoload proofs.
 *
 * Discovers Composer via ExecutableFinder against the caller's restored PATH,
 * isolates COMPOSER_HOME/cache/config, disables packagist.org, and uses path
 * repositories only. Shared by installer and ExtensionManager tests.
 */
final class OfflineComposerExtensionFixture
{
    /**
     * @param callable(string): void $ensureDirectory
     *
     * @return array{
     *     composer: string,
     *     packageDir: string,
     *     fqcn: string,
     *     previousEnv: array<string, string|false|null>
     * }
     */
    public static function begin(
        string $workspace,
        callable $ensureDirectory,
        string $packageName = 'ineersa/hatfield-ext-file-rewind',
        string $packageVersion = '0.0.42',
        string $psr4Namespace = 'HatfieldExtProbe\\',
        string $classBasename = 'Probe',
    ): array {
        $finder = new ExecutableFinder();
        $composer = $finder->find('composer');
        if (null === $composer || !is_file($composer)) {
            throw new \RuntimeException('Composer executable was not found on the restored PATH.');
        }

        $packageDir = Path::join($workspace, 'packages', 'demo');
        $ensureDirectory(Path::join($packageDir, 'src'));
        file_put_contents(Path::join($packageDir, 'composer.json'), json_encode([
            'name' => $packageName,
            'version' => $packageVersion,
            'autoload' => [
                'psr-4' => [
                    $psr4Namespace => 'src/',
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents(
            Path::join($packageDir, 'src', $classBasename.'.php'),
            "<?php\n\nnamespace ".rtrim($psr4Namespace, '\\').";\n\nfinal class {$classBasename} {}\n",
        );

        $composerHome = Path::join($workspace, 'composer-home');
        $composerCache = Path::join($workspace, 'composer-cache');
        $ensureDirectory($composerHome);
        $ensureDirectory($composerCache);

        $previousEnv = self::captureEnv([
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'COMPOSER_HTACCESS_PROTECT',
            'COMPOSER_DISABLE_XDEBUG_WARN',
        ]);
        self::putEnv('COMPOSER_HOME', $composerHome);
        self::putEnv('COMPOSER_CACHE_DIR', $composerCache);
        self::putEnv('COMPOSER_HTACCESS_PROTECT', '0');
        self::putEnv('COMPOSER_DISABLE_XDEBUG_WARN', '1');

        return [
            'composer' => $composer,
            'packageDir' => $packageDir,
            'fqcn' => rtrim($psr4Namespace, '\\').'\\'.$classBasename,
            'previousEnv' => $previousEnv,
        ];
    }

    /**
     * @param array<string, string|false|null> $previousEnv
     */
    public static function restoreEnv(array $previousEnv): void
    {
        foreach ($previousEnv as $name => $value) {
            if (false === $value || null === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }
            self::putEnv($name, (string) $value);
        }
    }

    public static function writeOfflineManifest(string $root, string $packageDir): void
    {
        $relativePackage = Path::makeRelative($packageDir, $root);
        file_put_contents(Path::join($root, 'composer.json'), json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
            ],
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => $relativePackage,
                    'options' => ['symlink' => false],
                ],
            ],
            'config' => [
                'prepend-autoloader' => false,
                'sort-packages' => true,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Install into a layer root without the production suffix pin, producing the
     * colliding content-hash ComposerAutoloaderInit class name.
     */
    public static function installWithoutSuffix(
        string $composer,
        string $root,
        string $package,
        string $version,
    ): string {
        $process = new Process(
            [$composer, 'require', $package.':'.$version, '--no-interaction', '--no-ansi', '--working-dir='.$root],
            $root,
            null,
            null,
            30.0,
        );
        $process->mustRun();

        $autoloadReal = Path::join($root, 'vendor/composer/autoload_real.php');
        if (!is_file($autoloadReal)) {
            throw new \RuntimeException('Composer require did not generate autoload_real.php.');
        }

        return (string) file_get_contents($autoloadReal);
    }

    public static function initClassName(string $autoloadRealContents): string
    {
        if (1 !== preg_match('/class\s+(ComposerAutoloaderInit[A-Za-z0-9_]+)/', $autoloadRealContents, $match)) {
            throw new \RuntimeException('Unable to locate ComposerAutoloaderInit class in generated autoload_real.php.');
        }

        return $match[1];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string|false|null>
     */
    private static function captureEnv(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = getenv($name);
        }

        return $out;
    }

    private static function putEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
