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
        // This journey uniquely needs real Composer and PHAR-owned autoloaders:
        // source dev mappings conceal stale loaders. Two physical roots and four
        // offline installs can exceed 10s in total; each readiness cap is finite,
        // observes terminal/file state, and is not an elapsed synchronization delay.
        $root = ProjectDir::get();
        $temporary = TestDirectoryIsolation::createProjectTempDir('extension-setup-command');
        $tmux = new TmuxHarness();
        $pane = null;
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
            $manifest = json_encode([
                'name' => 'test/extensions',
                'repositories' => [
                    ['packagist.org' => false],
                    ['type' => 'path', 'url' => $root.'/.hatfield/extensions/*'],
                    ['type' => 'path', 'url' => $root.'/vendor/*/*', 'options' => ['versions' => $versions]],
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], \JSON_THROW_ON_ERROR);
            foreach ([$home, $project] as $directory) {
                mkdir($directory.'/.hatfield/extensions', recursive: true);
                file_put_contents($directory.'/.hatfield/extensions/composer.json', $manifest);
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
                'extensions' => ['settings' => ['observational_memory' => ['semantic' => null]]],
            ];
            file_put_contents($path, Yaml::dump($initial, 8));
            $start = static fn (string $cwd): TmuxPane => $tmux->startDetached(
                'env HOME='.escapeshellarg($home).' COMPOSER_HOME='.escapeshellarg($temporary.'/composer')
                .' COMPOSER_CACHE_DIR='.escapeshellarg($temporary.'/composer-cache').' APP_ENV=prod APP_DEBUG=0 HATFIELD_CWD='.escapeshellarg($cwd)
                .' '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($phar).' extensions:setup',
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
            $this->assertSame($user, Yaml::parseFile($userPath));
            $homeManifest = json_decode(file_get_contents($home.'/.hatfield/extensions/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $send('C-d');
            $tmux->waitUntilPaneExits($pane, 3);

            $pane = $start($project);
            $tmux->waitForCaptureContains($pane, 'Scope: Project', 10);
            $send('Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'max_retained_turns', 10);
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
            // A second package unavailable from either the PHAR or user root must
            // configure in this SAME process, rather than requiring a restart.
            $send('Down', 'Down', 'Down', 'Enter', 'Down', 'Enter');
            $tmux->waitForCaptureContains($pane, 'task_root', 10);
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
