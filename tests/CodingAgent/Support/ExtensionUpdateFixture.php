<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Extension\ExtensionUpdateService;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Real Composer, offline path repositories, and only disposable installation roots. */
final class ExtensionUpdateFixture
{
    public readonly string $directory;
    public readonly Filesystem $filesystem;
    public readonly string $composer;

    /** @var array<string, string|false> */
    private array $environment = [];

    public function __construct()
    {
        $this->directory = TestDirectoryIsolation::createProjectTempDir('extension-update');
        $this->filesystem = new Filesystem();
        $this->composer = (new ExecutableFinder())->find('composer') ?? throw new \RuntimeException('Composer is required for the offline extension-update tests.');
        foreach ([
            'COMPOSER_HOME' => $this->directory.'/composer-home',
            'COMPOSER_CACHE_DIR' => $this->directory.'/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_AUTH' => '{}',
        ] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv($name.'='.$value);
        }
    }

    public function service(SerializerInterface $serializer, ValidatorInterface $validator, ?LockFactory $locks = null): ExtensionUpdateService
    {
        return new ExtensionUpdateService(
            new ExtensionPackageInstaller(new SettingsPathResolver($this->directory, $this->directory.'/home'), $this->filesystem),
            $this->filesystem, $serializer, $validator, $locks ?? new LockFactory(new InMemoryStore()), $this->directory.'/cache',
        );
    }

    public function project(): string
    {
        return $this->directory.'/project';
    }

    public function root(string $scope): string
    {
        return $this->directory.'/'.$scope.'/.hatfield/extensions';
    }

    public function seed(string $scope = 'project', bool $dependency = false, bool $devMode = false): void
    {
        $root = $this->root($scope);
        $this->filesystem->mkdir($root);
        $this->writePackage($scope, '1.0.0', $dependency ? '^1.0' : null);
        if ($dependency || $devMode) {
            $this->writeDependency($scope, '1.0.0');
        }
        $manifest = [
            'name' => 'hatfield/test-extensions', 'require' => ['test/update-extension' => '^1.0'],
            'repositories' => [
                'packagist.org' => false,
                'local' => ['type' => 'path', 'url' => '../../packages/*', 'options' => ['symlink' => false]],
            ],
            'config' => ['prepend-autoloader' => false, 'autoloader-suffix' => 'UpdateFixture'.md5($root)],
            // An update must not execute user/package scripts at either stage.
            'scripts' => ['post-update-cmd' => '@php -r "exit(97);"', 'post-install-cmd' => '@php -r "exit(98);"'],
        ];
        if ($devMode && !$dependency) {
            $manifest['require-dev'] = ['test/update-dependency' => '^1.0'];
        }
        $this->filesystem->dumpFile($root.'/composer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        (new Process(
            [$this->composer, 'update', ...($devMode ? [] : ['--no-dev']), '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi'],
            $root, ExtensionUpdateService::environment($root), timeout: 10,
        ))->mustRun();
    }

    public function writeAuthentication(string $scope = 'project'): void
    {
        $root = realpath($this->root($scope));
        if (false === $root || !Path::isBasePath(realpath($this->directory), $root) || is_link($root.'/auth.json')) {
            throw new \LogicException('Authentication fixtures must stay inside the disposable installation.');
        }
        $this->filesystem->dumpFile($root.'/auth.json', json_encode([
            'http-basic' => ['private.example' => ['username' => 'fixture-user', 'password' => 'fixture-password']],
        ], \JSON_THROW_ON_ERROR));
    }

    public function writePackage(string $scope, string $version, ?string $dependency = null): void
    {
        $path = $this->directory.'/'.$scope.'/packages/ext';
        $this->filesystem->dumpFile($path.'/composer.json', json_encode([
            'name' => 'test/update-extension', 'type' => 'hatfield-extension', 'version' => $version,
            'require' => null === $dependency ? new \stdClass() : ['test/update-dependency' => $dependency],
            'autoload' => ['psr-4' => ['HatfieldUpdateFixture\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        $this->filesystem->dumpFile($path.'/src/Version.php', '<?php namespace HatfieldUpdateFixture; class Version { const VALUE = '.var_export($version, true).'; }');
    }

    public function writeDependency(string $scope, string $version): void
    {
        $this->filesystem->dumpFile($this->directory.'/'.$scope.'/packages/dependency/composer.json', json_encode([
            'name' => 'test/update-dependency', 'version' => $version,
        ], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    public function snapshot(string $scope = 'project'): array
    {
        $result = [];
        foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json', 'vendor/composer/installed.php', 'vendor/autoload.php', 'vendor/test/update-extension/src/Version.php'] as $path) {
            $result[$path] = file_get_contents($this->root($scope).'/'.$path);
        }
        if (is_file($this->root($scope).'/auth.json')) {
            $result['auth.json'] = file_get_contents($this->root($scope).'/auth.json');
        }

        return $result;
    }

    public function loadedVersion(string $scope = 'project'): string
    {
        $process = new Process([\PHP_BINARY, '-r', 'require $argv[1]; echo \\HatfieldUpdateFixture\\Version::VALUE;', $this->root($scope).'/vendor/autoload.php'], $this->directory, timeout: 10);
        $process->mustRun();

        return $process->getOutput();
    }

    public function close(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
        TestDirectoryIsolation::removeDirectory($this->directory);
    }
}
