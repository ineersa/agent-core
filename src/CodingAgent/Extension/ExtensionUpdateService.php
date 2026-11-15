<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Extension\DTO\ExtensionComposerLockDTO;
use Ineersa\CodingAgent\Extension\DTO\ExtensionComposerPackageDTO;
use Ineersa\CodingAgent\Extension\DTO\ExtensionUpdatePlanDTO;
use Ineersa\CodingAgent\Extension\Exception\ExtensionUpdateException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Resolve outside the installations, then install only the approved lockfiles. */
final class ExtensionUpdateService
{
    private const array FLAGS = ['--no-interaction', '--no-ansi', '--no-plugins', '--no-scripts'];

    /** @var list<string> */
    private array $workspaces = [];

    /** @var list<LockInterface> */
    private array $locks = [];

    public function __construct(
        private readonly ExtensionPackageInstaller $installer,
        private readonly Filesystem $filesystem,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly LockFactory $lockFactory,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDirectory,
    ) {
    }

    /** @return list<ExtensionUpdatePlanDTO> */
    public function prepare(string $cwd): array
    {
        $plans = [];
        $seen = [];
        try {
            foreach ([SettingsLayerEnum::User, SettingsLayerEnum::Project] as $layer) {
                $root = realpath($this->installer->rootFor($layer, $cwd));
                if (false === $root || isset($seen[$root])) {
                    continue;
                }
                $seen[$root] = true;
                $this->assertInstallationBoundary($root);
                $installed = $this->installer->packages($layer, $cwd);
                $targets = array_keys(array_filter($installed, static fn (array $package): bool => 'hatfield-extension' === ($package['type'] ?? null) || isset($package['extra']['hatfield']['extension'])));
                if ([] === $targets) {
                    continue;
                }
                $lock = $this->lockFactory->createLock('hatfield-extension-update-'.hash('sha256', $root), ttl: null);
                if (!$lock->acquire()) {
                    throw new ExtensionUpdateException('Another Hatfield startup is checking or updating '.$root);
                }
                $this->locks[] = $lock;
                $aliases = [];
                $scopes = [];
                foreach ([SettingsLayerEnum::User, SettingsLayerEnum::Project] as $aliasLayer) {
                    $alias = $this->installer->rootFor($aliasLayer, $cwd);
                    if (realpath($alias) === $root) {
                        $aliases[] = $alias;
                        $scopes[] = $aliasLayer->value;
                    }
                }
                $plan = $this->prepareScope(implode('/', $scopes), $root, $targets, $aliases);
                if (null !== $plan) {
                    $plans[] = $plan;
                }
            }

            return $plans;
        } catch (\Throwable $e) {
            $this->cleanup();
            throw new ExtensionUpdateException('Extension update check failed: '.$e->getMessage(), previous: $e);
        }
    }

    /** @param list<ExtensionUpdatePlanDTO> $plans */
    public function apply(array $plans): void
    {
        // Validate every scope before touching any one of them. Recheck each
        // immediately before installation too; other Composer invocations do
        // not necessarily participate in Hatfield's cooperative startup locks.
        foreach ($plans as $plan) {
            $this->assertUnchanged($plan);
        }
        foreach ($plans as $plan) {
            $this->assertUnchanged($plan);
            $manifestHash = hash_file('sha256', $plan->root.'/composer.json');
            $process = new Process($plan->installCommand, $plan->root, self::environment($plan->root), timeout: 300);
            try {
                $this->filesystem->dumpFile($plan->root.'/composer.lock', $plan->lockContents);
                $process->mustRun();
                if (hash_file('sha256', $plan->root.'/composer.json') !== $manifestHash
                    || file_get_contents($plan->root.'/composer.lock') !== $plan->lockContents
                    || $this->versions($this->readInstalled($plan->root)) !== $this->versions($this->readLock($plan->root.'/composer.lock', $plan->devMode))
                ) {
                    throw new ExtensionUpdateException('Installed packages or Composer files do not match the approved plan in '.$plan->root);
                }
            } catch (\Throwable $e) {
                // A failed Composer install may be partial. Do not roll back a
                // lockfile alone, claim success, or start with mixed code.
                throw new ExtensionUpdateException('Extension update failed in '.$plan->root.'. Startup stopped; inspect/repair this installation before retrying. '.$e->getMessage(), previous: $e);
            }
        }
    }

    public function cleanup(): void
    {
        try {
            $this->filesystem->remove($this->workspaces);
        } finally {
            $this->workspaces = [];
            foreach ($this->locks as $lock) {
                $lock->release();
            }
            $this->locks = [];
        }
    }

    /** @return array<string, string> */
    public static function environment(string $root): array
    {
        return ['COMPOSER' => $root.'/composer.json', 'COMPOSER_VENDOR_DIR' => $root.'/vendor', 'COMPOSER_BIN_DIR' => $root.'/vendor/bin', 'COMPOSER_NO_DEV' => '0'];
    }

    /**
     * @param list<string> $targets
     * @param list<string> $aliases
     */
    private function prepareScope(string $scope, string $root, array $targets, array $aliases): ?ExtensionUpdatePlanDTO
    {
        if (!is_file($root.'/composer.json') || !is_file($root.'/composer.lock')) {
            throw new ExtensionUpdateException('Installed extensions require composer.json and composer.lock in '.$root);
        }
        $composer = (new ExecutableFinder())->find('composer');
        if (null === $composer) {
            throw new ExtensionUpdateException('Composer executable was not found on PATH.');
        }
        $this->assertLockEnabled($composer, $root);
        $state = $this->stateHash($root);
        $devMode = $this->readMetadata($root.'/vendor/composer/installed.json')->dev;
        $flags = [...($devMode ? [] : ['--no-dev']), ...self::FLAGS];
        $workspace = $this->cacheDirectory.'/extension-update-plans/'.Uuid::v7();
        $this->filesystem->mkdir($workspace, 0o700);
        $this->workspaces[] = $workspace;
        $this->filesystem->copy($root.'/composer.json', $workspace.'/composer.json');
        $this->filesystem->copy($root.'/composer.lock', $workspace.'/composer.lock');
        // Composer reads local auth beside COMPOSER's manifest, not its CWD.
        // Keep the copy private even when the original has broader permissions;
        // it shares the workspace's normal success/failure cleanup lifecycle.
        if (is_file($root.'/auth.json')) {
            $this->filesystem->copy($root.'/auth.json', $workspace.'/auth.json');
            $this->filesystem->chmod($workspace.'/auth.json', 0o600);
        }
        // Composer's working directory stays the original root so relative
        // repositories/platform configuration retain their normal meaning.
        // COMPOSER redirects all resolution writes to the disposable manifest
        // and lock; --no-install and the isolated vendor dir protect live code.
        $process = new Process(
            [$composer, 'update', ...$targets, '--with-all-dependencies', '--no-install', ...$flags],
            $root,
            self::environment($workspace),
            timeout: 300,
        );
        $process->mustRun();
        if ($this->stateHash($root) !== $state) {
            throw new ExtensionUpdateException('Installation changed while resolving '.$root.'; restart to review a fresh plan.');
        }
        $current = $this->versions($this->readInstalled($root));
        $proposed = $this->versions($this->readLock($workspace.'/composer.lock', $devMode));
        $changes = [];
        foreach (array_unique([...array_keys($current), ...array_keys($proposed)]) as $package) {
            if (($current[$package] ?? null) !== ($proposed[$package] ?? null)) {
                $changes[] = ['package' => $package, 'current' => $current[$package] ?? '(not installed)', 'target' => $proposed[$package] ?? '(removed)'];
            }
        }
        if ([] === $changes) {
            return null;
        }

        return new ExtensionUpdatePlanDTO(
            $scope, $root, $state, file_get_contents($workspace.'/composer.lock'), $changes,
            [$composer, '--working-dir='.$root, 'install', ...$flags], $devMode, $aliases,
        );
    }

    private function assertUnchanged(ExtensionUpdatePlanDTO $plan): void
    {
        $this->assertInstallationBoundary($plan->root);
        foreach ($plan->aliases as $alias) {
            if (realpath($alias) !== $plan->root) {
                throw new ExtensionUpdateException('Extension scope path changed after the preview: '.$alias);
            }
        }
        if (realpath($plan->root) !== $plan->root || $this->stateHash($plan->root) !== $plan->stateHash) {
            throw new ExtensionUpdateException('Extension installation changed after the preview in '.$plan->root.'; no update was started for this scope. Restart to review a fresh plan.');
        }
        $this->assertLockEnabled($plan->installCommand[0], $plan->root);
    }

    private function assertLockEnabled(string $composer, string $root): void
    {
        // config.lock=false makes Composer install resolve again despite the
        // approved lock. Ask Composer for effective config (including global
        // config), rather than assuming the installation manifest is sufficient.
        $process = new Process([$composer, 'config', 'lock', '--no-plugins', '--no-interaction', '--no-ansi'], $root, self::environment($root), timeout: 30);
        $process->mustRun();
        if ('true' !== trim($process->getOutput())) {
            throw new ExtensionUpdateException('Composer lockfiles must be enabled to apply an approved update plan in '.$root);
        }
    }

    private function assertInstallationBoundary(string $root): void
    {
        // Scope aliases may share a physical root, but Composer's manifest,
        // lock and vendor metadata must not redirect writes into an application
        // or another installation through internal symlinks.
        foreach (['composer.json', 'composer.lock', 'vendor', 'vendor/composer', 'vendor/composer/installed.json', 'vendor/composer/installed.php'] as $path) {
            $absolute = $root.'/'.$path;
            if (!file_exists($absolute) && !is_link($absolute)) {
                continue;
            }
            $resolved = realpath($absolute);
            if (false === $resolved || !Path::isBasePath($root, $resolved)) {
                throw new ExtensionUpdateException('Composer path escapes the extension installation: '.$absolute);
            }
        }
    }

    private function stateHash(string $root): string
    {
        $state = [];
        foreach (['composer.json', 'composer.lock', 'auth.json', 'vendor/composer/installed.json', 'vendor/composer/installed.php', 'vendor/autoload.php', 'vendor/composer/autoload_real.php'] as $path) {
            $state[$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : null;
        }

        return hash('sha256', serialize($state));
    }

    /** @return list<ExtensionComposerPackageDTO> */
    private function readLock(string $path, bool $includeDev): array
    {
        $lock = $this->readMetadata($path);

        return [...$lock->packages, ...($includeDev ? $lock->packagesDev : [])];
    }

    private function readMetadata(string $path): ExtensionComposerLockDTO
    {
        $lock = $this->serializer->deserialize(file_get_contents($path), ExtensionComposerLockDTO::class, 'json');
        $errors = $this->validator->validate($lock);
        if (0 !== \count($errors)) {
            throw new ExtensionUpdateException('Invalid Composer package metadata in '.$path.': '.$errors);
        }

        return $lock;
    }

    /** @return list<ExtensionComposerPackageDTO> */
    private function readInstalled(string $root): array
    {
        // Composer 2 installed.json uses the same packages envelope as a lock.
        return $this->readMetadata($root.'/vendor/composer/installed.json')->packages;
    }

    /**
     * @param list<ExtensionComposerPackageDTO> $packages
     *
     * @return array<string, string>
     */
    private function versions(array $packages): array
    {
        $versions = [];
        foreach ($packages as $package) {
            $reference = $package->source['reference'] ?? $package->dist['reference'] ?? null;
            if (null !== $reference && !\is_string($reference)) {
                throw new ExtensionUpdateException('Invalid Composer reference for '.$package->name);
            }
            $versions[$package->name] = $package->version.(null === $reference ? '' : ' @ '.$reference);
        }
        ksort($versions);

        return $versions;
    }
}
