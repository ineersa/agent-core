<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Composer\InstalledVersions;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\FileRewindExtension;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[Group('tui-e2e-replay')]
final class ExtensionsSetupCommandTest extends TestCase
{
    public function testFixtureIsRetainedUntilShutdownSucceeds(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-retention');
        try {
            file_put_contents($temporary.'/stderr.log', 'synthetic shutdown evidence');
            $this->removeFixtureAfterShutdown($temporary, new \RuntimeException('synthetic shutdown refusal'));
            $this->assertDirectoryExists($temporary);
            $this->assertSame('synthetic shutdown evidence', file_get_contents($temporary.'/stderr.log'));

            $this->removeFixtureAfterShutdown($temporary, null);
            $this->assertDirectoryDoesNotExist($temporary);
        } finally {
            TestDirectoryIsolation::removeDirectory($temporary);
        }
    }

    public function testFailureDiagnosticsRetainOwnedPaneEvidence(): void
    {
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-diagnostics');
        $tmux = new TmuxHarness();
        $tmux->setSnapshotDir($temporary);
        $stderr = $temporary.'/stderr.log';
        $pane = null;
        try {
            $pane = $tmux->startDetached('exec '.escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg(
                'fwrite(STDERR, "synthetic startup diagnostic\n"); fwrite(STDOUT, "diagnostics ready pid=".getmypid()."\n"); fread(STDIN, 1);'
            ).' 2>'.escapeshellarg($stderr), prefix: 'extension-setup-diagnostics');
            $tmux->waitForCaptureContains($pane, 'diagnostics ready', 3);
            $tmux->saveFailureDiagnostics($pane, $stderr);

            $reports = (string) getenv('HATFIELD_QA_REPORTS_DIR');
            if (!str_starts_with($reports, '/')) {
                $reports = ProjectDir::get().'/'.$reports;
            }
            $diagnostics = $reports.'/diagnostics/'.basename($temporary);
            $this->assertSame("synthetic startup diagnostic\n", file_get_contents($diagnostics.'/stderr.log'));
            $this->assertStringContainsString('diagnostics ready', file_get_contents($diagnostics.'/failure.txt'));
            $this->assertCount(1, glob($diagnostics.'/failure-*.ansi'));
            $processes = json_decode(file_get_contents($diagnostics.'/processes.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame($pane->paneId, $processes['pane']);
            $pid = $tmux->panePid($pane);
            $this->assertSame($pid, $processes['pane_pid']);
            $this->assertStringContainsString('pid='.$pid.' dead=0', $processes['pane_state']);
            $this->assertIsBool($processes['pane_proc_readable']);
            $this->assertIsArray($processes['processes']);
        } finally {
            try {
                if (null !== $pane && $tmux->paneExists($pane)) {
                    $tmux->sendKey($pane, 'C-d');
                    $tmux->waitUntilPaneExits($pane, 3);
                }
            } finally {
                $tmux->killAll();
                TestDirectoryIsolation::removeDirectory($temporary);
            }
        }
    }

    public function testRegisteredCommandInstallsConfiguresAndExitsThroughRealTerminalInput(): void
    {
        // This journey uniquely needs real Composer and PHAR-owned autoloaders:
        // source dev mappings conceal stale loaders. Multiple physical roots and
        // offline installs can exceed 10s in total; each readiness cap is finite,
        // observes terminal/file state, and is not an elapsed synchronization delay.
        $root = ProjectDir::get();
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-command');
        $tmux = new TmuxHarness();
        $tmux->setSnapshotDir($temporary);
        $stderr = $temporary.'/stderr.log';
        $pane = null;
        $failure = null;
        try {
            $home = $temporary.'/home';
            $project = $temporary.'/project';
            $path = $project.'/.hatfield/settings.yaml';
            $userPath = $home.'/.hatfield/settings.yaml';
            $phar = $root.'/var/tmp/phar/hatfield.phar';
            $this->assertFileDoesNotExist('phar://'.$phar.'/.hatfield/extensions/file-rewind/src/FileRewindExtension.php');
            $versions = [];
            foreach (InstalledVersions::getInstalledPackages() as $name) {
                if (null !== $version = InstalledVersions::getPrettyVersion($name)) {
                    $versions[$name] = $version;
                }
            }
            $older = $temporary.'/older-file-rewind';
            (new Filesystem())->mirror($root.'/.hatfield/extensions/file-rewind', $older);
            $olderManifest = json_decode(file_get_contents($older.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $olderManifest['version'] = '0.0.41';
            file_put_contents($older.'/composer.json', json_encode($olderManifest, \JSON_THROW_ON_ERROR));
            $configuration = $older.'/src/FileRewindConfiguration.php';
            file_put_contents($configuration, str_replace('defaultValue(FileRewindConfig::DEFAULT_MAX_RETAINED_TURNS)', 'defaultValue(31)', file_get_contents($configuration)));
            $manifest = [
                'name' => 'test/extensions',
                'repositories' => [
                    ['packagist.org' => false],
                    ['type' => 'path', 'url' => $root.'/.hatfield/extensions/*'],
                    ['type' => 'path', 'url' => $root.'/vendor/*/*', 'options' => ['versions' => $versions]],
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ];
            foreach ([$home, $project] as $directory) {
                mkdir($directory.'/.hatfield/extensions', recursive: true);
                $scoped = $manifest;
                if ($home === $directory) {
                    array_unshift($scoped['repositories'], ['type' => 'path', 'url' => $older]);
                }
                file_put_contents($directory.'/.hatfield/extensions/composer.json', json_encode($scoped, \JSON_THROW_ON_ERROR));
            }
            $user = [
                'ai' => ['providers' => [
                    'global' => ['models' => ['current' => []]],
                    'restored' => ['models' => ['model' => []]],
                ]],
                'extensions' => ['settings' => ['observational_memory' => [
                    'model' => 'global/current',
                    'semantic' => ['embedding_api' => ['base_url' => 'http://localhost/v1', 'model_id' => 'embedding'], 'reranker_api' => null],
                ]]],
            ];
            file_put_contents($userPath, Yaml::dump($user, 8));
            $initial = [
                'custom_kept' => true,
                'ai' => ['providers' => ['restored' => ['enabled' => false], 'project_only' => ['models' => ['model' => []]]]],
                'extensions' => ['enabled' => [TaskWorkflowExtension::class], 'settings' => ['observational_memory' => ['semantic' => null]]],
            ];
            file_put_contents($path, Yaml::dump($initial, 8));
            $start = static fn (string $cwd): TmuxPane => $tmux->startDetached(
                'env HOME='.escapeshellarg($home).' COMPOSER_HOME='.escapeshellarg($temporary.'/composer')
                .' COMPOSER_CACHE_DIR='.escapeshellarg($temporary.'/composer-cache').' APP_ENV=prod APP_DEBUG=0 HATFIELD_CWD='.escapeshellarg($cwd)
                .' '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($phar).' extensions:setup 2>>'.escapeshellarg($stderr),
                prefix: 'extension-setup-command', width: 80, height: 45, cwd: $cwd,
            );
            $send = static function (string ...$keys) use ($tmux, &$pane): void {
                foreach ($keys as $key) {
                    $tmux->sendKey($pane, $key);
                }
            };
            // Install at HOME using default Project, not User: the root's identity
            // must remain stable when another wizard later opens it as User.
            $pane = $start($home);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Enabled for parent', 10);
            $send('Escape');
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 3);
            $send('Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'max_retained_turns', 10);
            $this->assertMatchesRegularExpression('/max_retained_turns\s+31\b/', $tmux->capturePlain($pane), 'The disabled user copy has a distinguishable older schema.');
            $this->assertSame($user, Yaml::parseFile($userPath));
            $homeManifest = json_decode(file_get_contents($home.'/.hatfield/extensions/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);

            $pane = $start($project);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'max_retained_turns', 10);
            $this->assertMatchesRegularExpression('/max_retained_turns\s+100\b/', $tmux->capturePlain($pane), 'The first project loader must outrank the already-loaded user loader.');
            $this->assertSame($initial, Yaml::parseFile($path), 'Installation does not save settings.');
            $send('Enter', 'Down', 'Enter', 'Down', 'Enter', 'Down', 'Enter', 'Down', 'Enter', 'BSpace', 'BSpace', 'BSpace');
            $tmux->sendLiteral($pane, '7');
            $send('Enter', 'Down', 'Down', 'Enter');
            $tmux->waitForCallback($pane, static fn (): bool => 7 === (Yaml::parseFile($path)['extensions']['settings']['file_rewind']['max_retained_turns'] ?? null), 5, 'saved file-rewind settings');
            $saved = Yaml::parseFile($path);
            $this->assertTrue($saved['custom_kept']);
            $this->assertContains(FileRewindExtension::class, $saved['extensions']['enabled']);
            $this->assertContains(FileRewindExtension::class, $saved['forks']['extensions']['enabled']);
            $this->assertContains(FileRewindExtension::class, $saved['agents']['extensions']['always_on']);
            $projectManifest = json_decode(file_get_contents($project.'/.hatfield/extensions/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertNotSame($homeManifest['config']['autoloader-suffix'], $projectManifest['config']['autoloader-suffix']);
            // Restart with File Rewind installed and Task Workflow enabled but
            // missing: console startup caches the latter's failed class lookup.
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
            $pane = $start($project);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'task_root', 10);
            $this->assertArrayNotHasKey(TaskWorkflowExtension::class, require $project.'/.hatfield/extensions/vendor/composer/autoload_classmap.php', 'Unoptimized PSR lookup must recover from the startup miss without restarting.');
            $send('Escape');
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'semantic.embedding_api.base_url', 10);
            $this->assertNull(Yaml::parseFile($path)['extensions']['settings']['observational_memory']['semantic']);
            $send('Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'project_only/model', 3);
            $this->assertStringNotContainsString('restored/model', $tmux->capturePlain($pane));
            $send('Escape');
            $send('Down', 'Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->sendLiteral($pane, '{embedding_api: {base_url: http://localhost/v1, model_id: embedding}, reranker_api: null}');
            $send('Enter', ...array_fill(0, 14, 'Down'));
            $send('Enter');
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'semantic.embedding_api.base_url', 3);
            $send(...array_fill(0, 16, 'Down'));
            $send('Enter');
            $tmux->sendLiteral($pane, '{base_url: http://localhost/v1, model_id: reranker}');
            $send('Enter', ...array_fill(0, 6, 'Down'));
            $send('Enter');
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 3);
            $saved = Yaml::parseFile($path);
            $this->assertSame('reranker', $saved['extensions']['settings']['observational_memory']['semantic']['reranker_api']['model_id']);
            $send('Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Scope: User', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'semantic.embedding_api.base_url', 3);
            $send('Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'restored/model', 3);
            $this->assertStringNotContainsString('project_only/model', $tmux->capturePlain($pane));
            $send('Escape');
            $tmux->waitForCaptureContains($pane, 'semantic.embedding_api.base_url', 3);
            $send('Escape');
            $tmux->waitForCaptureContains($pane, 'Scope: User', 3);
            $this->assertSame($homeManifest, json_decode(file_get_contents($home.'/.hatfield/extensions/composer.json'), true, flags: \JSON_THROW_ON_ERROR), 'User/Project aliases keep the same physical-root suffix.');
            $this->assertSame($user, Yaml::parseFile($userPath));
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'allow_command_patterns', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter', 'BSpace', 'BSpace');
            $tmux->sendLiteral($pane, '[');
            $send('Enter', 'Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Malformed inline YAML', 3);
            $this->assertSame($user, Yaml::parseFile($userPath));
            $send('Up', 'Up', 'Up', 'Up', 'Enter', 'BSpace');
            $long = 'START-'.str_repeat('界é👩‍💻', 35).' END';
            $tmux->sendLiteral($pane, trim(Yaml::dump([$long, 'echo hello, world', '  literal spacing  '], 0)));
            $tmux->waitForCaptureContains($pane, 'END', 3);
            $this->assertStringNotContainsString('START-', $tmux->capturePlain($pane), 'Only the cursor viewport is rendered at 80 columns.');
            $send('Home');
            $tmux->waitForCaptureContains($pane, 'START-', 3);
            $send(...array_fill(0, 9, 'Right'));
            $send('BSpace');
            $tmux->sendLiteral($pane, '界');
            $send('End', 'Left', 'Right', 'BSpace');
            $tmux->sendLiteral($pane, ']');
            $send('Enter', 'Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Scope: User', 3);
            $this->assertSame([$long, 'echo hello, world', '  literal spacing  '], Yaml::parseFile($userPath)['extensions']['settings']['safe_guard']['allow_command_patterns']);
            $this->assertSame($saved, Yaml::parseFile($path), 'User edits never modify Project settings.');
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'allow_command_patterns', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->sendLiteral($pane, 'cancelled edit');
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
            $this->assertFalse($tmux->paneExists($pane));

            // Copying an installation preserves its generated bootstrap class.
            // Both physical roots must still load before setup can repair a suffix.
            $copied = $temporary.'/copied-project';
            (new Filesystem())->mirror($home.'/.hatfield/extensions', $copied.'/.hatfield/extensions');
            file_put_contents($copied.'/.hatfield/settings.yaml', Yaml::dump($initial, 8));
            $pane = $start($copied);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
            $this->assertFalse($tmux->paneExists($pane));

            // Startup declares the enabled old user entry before project install.
            // Unlike the disabled-version case, reordering loaders cannot replace it.
            $entry = $older.'/src/FileRewindExtension.php';
            file_put_contents($entry, str_replace('ConfigurableExtensionInterface', 'HatfieldExtensionInterface', file_get_contents($entry)));
            $enabledUser = Yaml::parseFile($userPath);
            $enabledUser['extensions']['enabled'] = [FileRewindExtension::class];
            file_put_contents($userPath, Yaml::dump($enabledUser, 8));
            $enabled = $temporary.'/enabled-project';
            mkdir($enabled.'/.hatfield/extensions', recursive: true);
            file_put_contents($enabled.'/.hatfield/extensions/composer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
            $enabledPath = $enabled.'/.hatfield/settings.yaml';
            file_put_contents($enabledPath, Yaml::dump(['custom_kept' => true]));
            $pane = $start($enabled);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Restart extensions:setup', 10);
            $this->assertFileExists($enabled.'/.hatfield/extensions/vendor/autoload.php');
            $this->assertSame(['custom_kept' => true], Yaml::parseFile($enabledPath), 'The restart refusal cannot save configuration or enablement.');
            $this->assertSame($enabledUser, Yaml::parseFile($userPath));
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
            $pane = $start($enabled);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'max_retained_turns', 3);
            $this->assertMatchesRegularExpression('/max_retained_turns\s+100\b/', $tmux->capturePlain($pane));
            $send('Down', 'Down', 'Down', 'Down', 'Enter', 'BSpace', 'BSpace', 'BSpace');
            $tmux->sendLiteral($pane, '9');
            $send('Enter', 'Down', 'Down', 'Enter');
            $tmux->waitForCallback($pane, static fn (): bool => 9 === (Yaml::parseFile($enabledPath)['extensions']['settings']['file_rewind']['max_retained_turns'] ?? null), 5, 'configured project release after restart');
            $this->assertTrue(Yaml::parseFile($enabledPath)['custom_kept']);
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
        } catch (\Throwable $exception) {
            $failure = $exception;
            if (null !== $pane) {
                try {
                    $tmux->saveFailureDiagnostics($pane, $stderr);
                } catch (\Throwable $diagnosticsFailure) {
                    fwrite(\STDERR, 'Extension setup diagnostics failed: '.$diagnosticsFailure->getMessage().\PHP_EOL);
                }
            }

            throw $exception;
        } finally {
            $cleanupFailure = null;
            foreach ([
                static function () use ($tmux, $pane): void {
                    if (null !== $pane && $tmux->paneExists($pane)) {
                        $tmux->sendKey($pane, 'C-d');
                        $tmux->waitUntilPaneExits($pane, 3);
                    }
                },
                static fn () => $tmux->killAll(),
            ] as $cleanup) {
                try {
                    $cleanup();
                } catch (\Throwable $exception) {
                    $cleanupFailure ??= $exception;
                    fwrite(\STDERR, 'Extension setup cleanup failed: '.$exception->getMessage().\PHP_EOL);
                }
            }
            try {
                $this->removeFixtureAfterShutdown($temporary, $cleanupFailure);
            } catch (\Throwable $exception) {
                $cleanupFailure ??= $exception;
                fwrite(\STDERR, 'Extension setup fixture cleanup failed: '.$exception->getMessage().\PHP_EOL);
            }
            if (null === $failure && null !== $cleanupFailure) {
                throw $cleanupFailure;
            }
        }
    }

    private function removeFixtureAfterShutdown(string $temporary, ?\Throwable $shutdownFailure): void
    {
        if (null !== $shutdownFailure) {
            fwrite(\STDERR, 'Retaining extension setup fixture after failed shutdown: '.$temporary.\PHP_EOL);

            return;
        }
        TestDirectoryIsolation::removeDirectory($temporary);
    }
}
