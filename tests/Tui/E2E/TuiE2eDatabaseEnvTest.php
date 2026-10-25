<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv
 */
final class TuiE2eDatabaseEnvTest extends TestCase
{
    public function testShellPrefixSetsAppDatabaseEnvVar(): void
    {
        $prefix = TuiE2eDatabaseEnv::shellPrefix(
            'app_test-abc.sqlite',
        );

        $this->assertStringContainsString('HATFIELD_TEST_DATABASE_PATH=', $prefix);
        $this->assertStringContainsString('app_test-abc.sqlite', $prefix);
        $this->assertStringNotContainsString('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=', $prefix);
    }

    public function testShellPrefixWithSourceMessengerInstallsPassThroughWrapper(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-source-messenger');

        try {
            $prefix = TuiE2eDatabaseEnv::shellPrefixWithSourceMessenger(
                'app_test-source.sqlite',
                $dir,
            );

            $this->assertStringContainsString('HATFIELD_TEST_DATABASE_PATH=', $prefix);
            $this->assertStringContainsString('HATFIELD_BINARY_PATH=', $prefix);

            if (!preg_match("/HATFIELD_BINARY_PATH='([^']+)'/", $prefix, $matches)) {
                $this->fail('Expected escaped HATFIELD_BINARY_PATH in shell prefix');
            }
            $wrapper = $matches[1];
            $this->assertFileExists($wrapper);
            $source = (string) file_get_contents($wrapper);
            $this->assertStringNotContainsString('--sleep=', $source);
            $this->assertStringContainsString('HATFIELD_REPLAY_SESSION_ID', $source);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testDoctrineEnvPathResolvesToIsolatedAbsolutePath(): void
    {
        $kernelRoot = '/worktree';
        $isolated = '/worktree/var/tmp/tui-e2e-repair-deadbeef';
        $basename = 'app_test-tui-repair-cafe.sqlite';

        $absolute = TuiE2eDatabaseEnv::isolatedSqliteAbsolutePath($isolated, $basename);
        $envPath = TuiE2eDatabaseEnv::doctrineEnvPathForIsolatedSqlite($kernelRoot, $isolated, $basename);

        $this->assertSame($isolated.'/.hatfield/tmp/test-db/'.$basename, $absolute);
        $this->assertSame(
            '../tmp/tui-e2e-repair-deadbeef/.hatfield/tmp/test-db/'.$basename,
            $envPath,
        );
        $doctrineResolved = $kernelRoot.'/var/test/'.$envPath;
        $this->assertStringEndsWith('/.hatfield/tmp/test-db/'.$basename, $doctrineResolved);
        $this->assertStringContainsString('/var/test/../tmp/', $doctrineResolved);
    }

    public function testReplayBaseSettingsReturnsCanonicalDefaultModelAndProvider(): void
    {
        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        $this->assertSame('llama_cpp_test/test', $settings['ai']['default_model']);
        $this->assertArrayHasKey('llama_cpp_test', $settings['ai']['providers']);
    }

    public function testWriteReplaySettingsWritesBothFilesWithSingleLlmWorker(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-settings');
        TestDirectoryIsolation::createHatfieldTree($dir);

        try {
            TuiE2eDatabaseEnv::writeReplaySettings($dir, TuiE2eDatabaseEnv::replayBaseSettings());

            $project = $dir.'/.hatfield/settings.yaml';
            $home = $dir.'/home/.hatfield/settings.yaml';
            $this->assertFileExists($project);
            $this->assertFileExists($home);

            $yaml = file_get_contents($project);
            $this->assertIsString($yaml);
            $this->assertStringContainsString('max_parallelism: 1', $yaml);
            $this->assertSame($yaml, file_get_contents($home));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
