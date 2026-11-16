<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\Exception\ExtensionUpdateException;
use Ineersa\CodingAgent\Extension\ExtensionUpdateService;
use Ineersa\CodingAgent\Tests\Support\ExtensionUpdateFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ExtensionUpdateServiceTest extends IsolatedKernelTestCase
{
    private ExtensionUpdateFixture $fixture;
    private ExtensionUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new ExtensionUpdateFixture();
        $this->service = $this->fixture->service(self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
    }

    protected function tearDown(): void
    {
        $this->service->cleanup();
        $this->fixture->close();
        parent::tearDown();
    }

    public function testRealComposerPlansDependenciesReadOnlyAndInstallsOnlyTheApprovedLock(): void
    {
        $this->fixture->seed(dependency: true);
        $before = $this->fixture->snapshot();
        $this->fixture->writePackage('project', '1.1.0', '^1.1');
        $this->fixture->writeDependency('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->assertCount(1, $plans);
        $this->assertSame($this->fixture->root('project'), $plans[0]->root);
        $this->assertSame(['test/update-dependency', 'test/update-extension'], array_column($plans[0]->changes, 'package'));
        foreach ($plans[0]->changes as $change) {
            $this->assertStringStartsWith('1.0.0', $change['current']);
            $this->assertStringStartsWith('1.1.0', $change['target']);
        }
        $this->assertSame($before, $this->fixture->snapshot());
        $this->assertFalse(class_exists('HatfieldUpdateFixture\\Version', false));
        // A newer candidate appears after the preview. Installation must use
        // the approved lock, not silently resolve and install this new version.
        $newer = $this->fixture->directory.'/project/packages/newer';
        $this->fixture->filesystem->mirror($this->fixture->directory.'/project/packages/ext', $newer);
        $this->fixture->filesystem->dumpFile($newer.'/composer.json', str_replace('1.1.0', '1.2.0', file_get_contents($newer.'/composer.json')));
        $this->fixture->filesystem->dumpFile($newer.'/src/Version.php', str_replace('1.1.0', '1.2.0', file_get_contents($newer.'/src/Version.php')));
        $this->service->apply($plans);
        $this->assertSame($plans[0]->lockContents, file_get_contents($plans[0]->root.'/composer.lock'));
        $this->assertSame($before['composer.json'], file_get_contents($plans[0]->root.'/composer.json'));
        $this->assertSame('1.1.0', $this->fixture->loadedVersion());
    }

    public function testLocalAuthenticationRemainsAvailableToComposerInAPrivateWorkspace(): void
    {
        $this->fixture->seed();
        $this->fixture->writeAuthentication();
        $sourceAuth = $this->fixture->root('project').'/auth.json';
        $this->fixture->filesystem->chmod($sourceAuth, 0o644);
        $this->fixture->writePackage('project', '1.1.0');
        $before = $this->fixture->snapshot();
        $plans = $this->service->prepare($this->fixture->project());
        $this->assertCount(1, $plans);
        $workspaces = glob($this->fixture->directory.'/cache/extension-update-plans/*', \GLOB_ONLYDIR);
        $this->assertCount(1, $workspaces);
        $workspace = $workspaces[0];
        $this->assertSame($before['auth.json'], file_get_contents($workspace.'/auth.json'));
        $this->assertSame(0o700, fileperms($workspace) & 0o777);
        $this->assertSame(0o600, fileperms($workspace.'/auth.json') & 0o777);

        // Exercise Composer's real auth lookup with the redirected manifest,
        // without a network server or exposing real/inherited credentials.
        $auth = new Process(
            [$this->fixture->composer, 'config', '--auth', 'http-basic.private.example', '--no-plugins', '--no-interaction', '--no-ansi'],
            $plans[0]->root, ExtensionUpdateService::environment($workspace), timeout: 10,
        );
        $auth->mustRun();
        $this->assertSame(['username' => 'fixture-user', 'password' => 'fixture-password'], json_decode($auth->getOutput(), true, flags: \JSON_THROW_ON_ERROR));
        $this->service->apply($plans);
        $this->assertSame($before['auth.json'], file_get_contents($sourceAuth));
        $this->assertSame(0o644, fileperms($sourceAuth) & 0o777);
        $this->service->cleanup();
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function testUpToDateAndAbsentInstallationsHaveNoPlan(): void
    {
        $this->assertSame([], $this->service->prepare($this->fixture->project()));
        $this->fixture->seed();
        $before = $this->fixture->snapshot();
        $this->assertSame([], $this->service->prepare($this->fixture->project()));
        $this->assertSame($before, $this->fixture->snapshot());
    }

    public function testBothScopesUpdateIndependentlyAndProjectAutoloadingStillWins(): void
    {
        $this->fixture->seed('home');
        $this->fixture->seed('project');
        $this->fixture->writePackage('home', '1.1.0');
        $this->fixture->writePackage('project', '1.2.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->assertSame(['user', 'project'], array_column($plans, 'scope'));
        $this->service->apply($plans);
        $this->assertSame('1.1.0', $this->fixture->loadedVersion('home'));
        $this->assertSame('1.2.0', $this->fixture->loadedVersion());
        $loaded = new Process([\PHP_BINARY, '-r', 'require $argv[1]; require $argv[2]; echo \\HatfieldUpdateFixture\\Version::VALUE;', $this->fixture->root('project').'/vendor/autoload.php', $this->fixture->root('home').'/vendor/autoload.php'], timeout: 10);
        $loaded->mustRun();
        $this->assertSame('1.2.0', $loaded->getOutput());
    }

    public function testIdenticalPhysicalRootsAreResolvedAndUpdatedOnce(): void
    {
        $this->fixture->seed();
        $this->fixture->filesystem->mkdir($this->fixture->directory.'/home/.hatfield');
        $this->fixture->filesystem->symlink($this->fixture->root('project'), $this->fixture->root('home'));
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->assertCount(1, $plans);
        $this->assertSame('user/project', $plans[0]->scope);
        $this->assertCount(2, $plans[0]->aliases);
        $this->service->apply($plans);
        $this->assertSame('1.1.0', $this->fixture->loadedVersion());
    }

    public function testChangedInstallationAbortsBeforeAnyLockOrPackageWrites(): void
    {
        $this->fixture->seed();
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $before = $this->fixture->snapshot();
        $this->fixture->filesystem->appendToFile($plans[0]->root.'/composer.json', "\n");
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('changed after the preview');
            $this->service->apply($plans);
        } finally {
            $this->assertSame($before['composer.lock'], file_get_contents($plans[0]->root.'/composer.lock'));
            $this->assertSame($before['vendor/composer/installed.json'], file_get_contents($plans[0]->root.'/vendor/composer/installed.json'));
        }
    }

    public function testChangedScopeAliasCannotUpdateTheWrongPhysicalInstallation(): void
    {
        $this->fixture->seed();
        $this->fixture->filesystem->mkdir($this->fixture->directory.'/home/.hatfield');
        $this->fixture->filesystem->symlink($this->fixture->root('project'), $this->fixture->root('home'));
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->fixture->filesystem->remove($this->fixture->root('home'));
        $this->fixture->filesystem->mkdir($this->fixture->root('home'));
        $this->expectException(ExtensionUpdateException::class);
        $this->expectExceptionMessage('scope path changed');
        $this->service->apply($plans);
    }

    public function testResolutionFailureLeavesTheInstallationUntouched(): void
    {
        $this->fixture->seed();
        $this->fixture->writeAuthentication();
        $this->fixture->writePackage('project', '2.0.0');
        $before = $this->fixture->snapshot();
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('check failed');
            $this->service->prepare($this->fixture->project());
        } finally {
            $this->assertSame($before, $this->fixture->snapshot());
            $this->assertSame([], glob($this->fixture->directory.'/cache/extension-update-plans/*', \GLOB_ONLYDIR));
        }
    }

    public function testInstallFailureIsReportedInsteadOfClaimingSuccess(): void
    {
        $this->fixture->seed();
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->fixture->filesystem->remove($this->fixture->directory.'/project/packages/ext');
        $this->expectException(ExtensionUpdateException::class);
        $this->expectExceptionMessage('Startup stopped');
        $this->service->apply($plans);
    }

    public function testExistingDevelopmentDependenciesArePreserved(): void
    {
        $this->fixture->seed(devMode: true);
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $this->assertTrue($plans[0]->devMode);
        $this->assertNotContains('--no-dev', $plans[0]->installCommand);
        $this->assertSame(['test/update-extension'], array_column($plans[0]->changes, 'package'));
        $this->service->apply($plans);
        $installed = json_decode(file_get_contents($plans[0]->root.'/vendor/composer/installed.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertCount(2, $installed['packages']);
        $this->assertTrue($installed['dev']);
    }

    public function testDisabledComposerLockingCannotSilentlyResolveAgainAfterConsent(): void
    {
        $this->fixture->seed();
        $path = $this->fixture->root('project').'/composer.json';
        $manifest = json_decode(file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        $manifest['config']['lock'] = false;
        $this->fixture->filesystem->dumpFile($path, json_encode($manifest, \JSON_THROW_ON_ERROR));
        $before = $this->fixture->snapshot();
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('lockfiles must be enabled');
            $this->service->prepare($this->fixture->project());
        } finally {
            $this->assertSame($before, $this->fixture->snapshot());
        }
    }

    public function testVendorSymlinksCannotRedirectUpdatesIntoAnotherApplication(): void
    {
        $this->fixture->seed();
        $root = $this->fixture->root('project');
        $foreign = $this->fixture->directory.'/foreign-application-vendor';
        $this->fixture->filesystem->rename($root.'/vendor', $foreign);
        $this->fixture->filesystem->symlink($foreign, $root.'/vendor');
        $before = file_get_contents($foreign.'/composer/installed.json');
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('escapes the extension installation');
            $this->service->prepare($this->fixture->project());
        } finally {
            $this->assertSame($before, file_get_contents($foreign.'/composer/installed.json'));
        }
    }

    public function testSecondScopeChangesAreRejectedBeforeUpdatingTheFirstScope(): void
    {
        $this->fixture->seed('home');
        $this->fixture->seed('project');
        $this->fixture->writePackage('home', '1.1.0');
        $this->fixture->writePackage('project', '1.1.0');
        $plans = $this->service->prepare($this->fixture->project());
        $before = $this->fixture->snapshot('home');
        $this->fixture->filesystem->appendToFile($this->fixture->root('project').'/composer.lock', "\n");
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->service->apply($plans);
        } finally {
            $this->assertSame($before, $this->fixture->snapshot('home'));
        }
    }

    public function testProductionWiringCoordinatesIndependentInstallationsThroughSharedFilesystemLocks(): void
    {
        $this->fixture->seed();
        $this->fixture->writePackage('project', '1.1.0');
        $container = self::getContainer();
        // Use the actual production updater binding, not a shared injected
        // InMemoryStore. A separate startup opens its own flock store at the
        // same home path regardless of app.cwd/kernel.project_dir/cache root.
        $first = $container->get('test.hatfield.extension_update.service');
        $this->assertInstanceOf(ExtensionUpdateService::class, $first);
        $home = $container->get(SettingsPathResolver::class)->getHomeDir();
        $second = $this->fixture->service(
            $container->get(SerializerInterface::class), $container->get(ValidatorInterface::class),
            new LockFactory(new FlockStore($home.'/.hatfield/tmp/extension-update-locks')),
        );
        $before = $this->fixture->snapshot();
        try {
            $this->assertCount(1, $first->prepare($this->fixture->project()));
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('Another Hatfield startup');
            $second->prepare($this->fixture->project());
        } finally {
            $first->cleanup();
            $second->cleanup();
            $this->assertSame($before, $this->fixture->snapshot());
            try {
                $this->assertCount(1, $second->prepare($this->fixture->project()), 'Releasing the owning startup must allow a new startup.');
            } finally {
                $second->cleanup();
            }
        }
    }
}
