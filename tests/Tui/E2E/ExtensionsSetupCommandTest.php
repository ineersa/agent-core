<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Composer\InstalledVersions;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\FileRewindExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[Group('tui-e2e-replay')]
final class ExtensionsSetupCommandTest extends TestCase
{
    public function testRegisteredCommandInstallsConfiguresAndExitsThroughRealTerminalInput(): void
    {
        // This command requires a real TTY. Exercise its registered entry point, native
        // widgets and real offline Composer; waits observe state, never elapsed delays.
        $root = ProjectDir::get();
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-command');
        $tmux = new TmuxHarness();
        $pane = null;
        try {
            mkdir($temporary.'/home', recursive: true);
            mkdir($temporary.'/project/.hatfield/extensions', recursive: true);
            $project = $temporary.'/project';
            $path = $project.'/.hatfield/settings.yaml';
            file_put_contents($path, "custom_kept: true\n");
            $versions = [];
            foreach (InstalledVersions::getInstalledPackages() as $name) {
                if (null !== $version = InstalledVersions::getPrettyVersion($name)) {
                    $versions[$name] = $version;
                }
            }
            file_put_contents($project.'/.hatfield/extensions/composer.json', json_encode([
                'name' => 'test/extensions',
                'repositories' => [
                    ['packagist.org' => false],
                    ['type' => 'path', 'url' => $root.'/.hatfield/extensions/file-rewind'],
                    ['type' => 'path', 'url' => $root.'/vendor/*/*', 'options' => ['versions' => $versions]],
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], \JSON_THROW_ON_ERROR));
            $pane = $tmux->startDetached(
                'env HOME='.escapeshellarg($temporary.'/home').' COMPOSER_HOME='.escapeshellarg($temporary.'/composer')
                .' COMPOSER_CACHE_DIR='.escapeshellarg($temporary.'/composer-cache').' APP_ENV=test HATFIELD_CWD='.escapeshellarg($project)
                .' '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/bin/console').' extensions:setup',
                prefix: 'extension-setup-command', width: 160, height: 45, cwd: $project,
            );
            $send = static function (string ...$keys) use ($tmux, $pane): void {
                foreach ($keys as $key) {
                    $tmux->sendKey($pane, $key);
                }
            };
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Cancel installation', 3);
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Enabled for parent', 10);
            $this->assertSame(['custom_kept' => true], Yaml::parseFile($path), 'Installation must not enable or configure anything.');
            $send('Enter', 'Down', 'Enter', 'Down', 'Enter', 'Down', 'Enter', 'Down', 'Enter');
            $send('BSpace', 'BSpace', 'BSpace');
            $tmux->sendLiteral($pane, '7');
            $send('Enter', 'Down', 'Down', 'Enter');
            $tmux->waitForCallback($pane, static fn (): bool => 7 === (Yaml::parseFile($path)['extensions']['settings']['file_rewind']['max_retained_turns'] ?? null), 5, 'saved file-rewind settings');
            $saved = Yaml::parseFile($path);
            $this->assertTrue($saved['custom_kept']);
            $this->assertContains(FileRewindExtension::class, $saved['extensions']['enabled']);
            $this->assertContains(FileRewindExtension::class, $saved['forks']['extensions']['enabled']);
            $this->assertContains(FileRewindExtension::class, $saved['agents']['extensions']['always_on']);
            $this->assertFalse($saved['extensions']['settings']['file_rewind']['enabled']);
            $send('Enter');
            $tmux->waitForCaptureContains($pane, 'User ~/.hatfield/settings.yaml', 3);
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Scope: User', 3);
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'allow_command_patterns', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter', 'BSpace', 'BSpace');
            $tmux->sendLiteral($pane, '[');
            $send('Enter', 'Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'Malformed inline YAML', 3);
            $userPath = $temporary.'/home/.hatfield/settings.yaml';
            $this->assertFileDoesNotExist($userPath, 'Invalid edits must refuse the complete save.');
            $send('Up', 'Up', 'Up', 'Up', 'Enter', 'BSpace');
            $tmux->sendLiteral($pane, "['echo hello, world', '  literal spacing  ']");
            $send('Enter', 'Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->waitForCallback($pane, static fn (): bool => is_file($userPath), 5, 'saved user SafeGuard list');
            $this->assertSame(['echo hello, world', '  literal spacing  '], Yaml::parseFile($userPath)['extensions']['settings']['safe_guard']['allow_command_patterns']);
            $this->assertArrayNotHasKey('enabled', Yaml::parseFile($userPath)['extensions'], 'Unchanged inherited enablement stays inherited.');
            $this->assertSame($saved, Yaml::parseFile($path), 'User saves cannot capture or modify project overrides.');
            $send('Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'allow_command_patterns', 3);
            $send('Down', 'Down', 'Down', 'Down', 'Enter');
            $tmux->sendLiteral($pane, 'cancelled edit');
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);
            $this->assertFalse($tmux->paneExists($pane));
            $this->assertSame(['echo hello, world', '  literal spacing  '], Yaml::parseFile($userPath)['extensions']['settings']['safe_guard']['allow_command_patterns']);
        } finally {
            if (null !== $pane && $tmux->paneExists($pane)) {
                $tmux->sendKey($pane, 'C-d');
                $tmux->waitUntilPaneExits($pane, 3);
            }
            $tmux->killAll();
            TestDirectoryIsolation::removeDirectory($temporary);
        }
    }
}
