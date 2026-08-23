<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Tui;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Ineersa\Tui\Tests\E2E\TmuxHarness;
use Ineersa\Tui\Tests\E2E\TmuxPane;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use PHPUnit\Framework\Attributes\Group;

/**
 * Minimal tmux proof for /om-status and /om-view with real project extension loading.
 *
 * Seeds isolated OM SQLite via kernel test-container OmDatabaseFactoryTestService.
 * Agent starts as a draft; first submit promotes a real session id. OM commands then
 * resolve that live id lazily via TuiExtensionContext::getSessionId() (not a cached
 * registration-time string). No live model required for local command path.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiOmCommandsE2eTest extends IsolatedKernelTestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $observationId;
    private string $seedDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->observationId = str_repeat('d', 64);
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
        $this->seedDbPath = $this->testProjectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $this->seedOmDatabasePlaceholder();
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        parent::tearDown();
    }

    public function testOmStatusAndViewRenderInRealTui(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-om-commands',
            width: 140,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            // Promote draft → real session (session_id is lazy until first submit).
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'om e2e seed');
            $this->tmux->sendKey($pane, 'Enter');
            $sessionId = $this->waitForLiveSessionId();
            $this->rebindSeedToSession($sessionId);

            // Cancel any in-flight LLM turn so local slash commands stay easy to see.
            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◆') || str_contains($cap, 'idle') || str_contains($cap, '█'),
                timeout: 8.0,
                message: 'TUI did not settle after cancel',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/om-status');
            $this->tmux->sendKey($pane, 'Enter');

            // Local command result is an in-memory transcript Markdown block.
            $statusViewport = $this->waitForViewport(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Observational memory')
                    && str_contains($cap, 'Observations:')
                    && str_contains($cap, 'Coverage:'),
                timeout: 12.0,
                message: '/om-status report not visible in current viewport',
            );
            $this->assertStringContainsString('Observational memory', $statusViewport);
            $this->assertStringContainsString('Durable memory state only', $statusViewport);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/om-view');
            $this->tmux->sendKey($pane, 'Enter');

            $shortId = substr($this->observationId, 0, 12);
            $viewViewport = $this->waitForViewport(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Observations')
                    && str_contains($cap, $shortId)
                    && str_contains($cap, 'hyphenated commands'),
                timeout: 12.0,
                message: '/om-view report not visible in current viewport',
            );
            $this->assertStringContainsString('Observations', $viewViewport);
            $this->assertStringContainsString($shortId, $viewViewport);
            $this->assertStringContainsString('hyphenated commands', $viewViewport);
            $this->assertStringContainsString('Sources:', $viewViewport);

            $this->saveAnsiSnapshot($pane, 'om-commands-smoke');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'om-commands-smoke-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Thesis: OM background activity status is visible once in the current viewport
     * status panel via the real poller path, not duplicated on the footer line.
     */
    public function testOmBackgroundStatusAppearsOnceNotInFooter(): void
    {
        $activityText = 'Observational memory: reflector running (~2,500 tokens)';

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-om-status',
            width: 140,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'om e2e status seed');
            $this->tmux->sendKey($pane, 'Enter');
            $sessionId = $this->waitForLiveSessionId();

            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◆') || str_contains($cap, 'idle') || str_contains($cap, '█'),
                timeout: 8.0,
                message: 'TUI did not settle after cancel',
                history: 2000,
            );

            $this->seedActivityForSession($sessionId);

            $viewport = $this->waitForViewport(
                $pane,
                static fn (string $cap): bool => str_contains($cap, $activityText)
                    && str_contains($cap, 'om-background'),
                timeout: 8.0,
                message: 'OM background status not visible in current viewport',
            );

            $this->assertSame(
                1,
                substr_count($viewport, $activityText),
                "activity text must appear exactly once in viewport:\n".$viewport,
            );
            $this->assertFooterLineDoesNotContain($viewport, $sessionId, $activityText);

            $this->clearActivityForSession($sessionId);
            $this->waitForViewport(
                $pane,
                static fn (string $cap): bool => !str_contains($cap, $activityText),
                timeout: 5.0,
                message: 'OM background status did not clear from viewport',
            );

            $this->saveAnsiSnapshot($pane, 'om-background-status-once');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'om-background-status-once-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-om-');

        // IsolatedKernelTestCase sets HATFIELD_CWD in the parent process; the tmux
        // agent must use the fixture project dir so sessions/OM DB land together.
        return \sprintf(
            'APP_ENV=test HATFIELD_CWD=%s %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            escapeshellarg($this->testProjectDir),
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-om');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // Project-local extension autoload (same path ExtensionManager loads).
        @mkdir($dir.'/.hatfield/extensions/vendor', 0o777, true);
        $srcRoot = $this->projectRoot.'/.hatfield/extensions/observational-memory/src';
        $autoload = "<?php\n"
            .'$srcRoot = '.var_export($srcRoot, true).";\n"
            ."spl_autoload_register(static function (string \$class) use (\$srcRoot): void {\n"
            ."    \$prefix = 'Ineersa\\\\HatfieldExt\\\\ObservationalMemory\\\\';\n"
            ."    if (!str_starts_with(\$class, \$prefix)) {\n"
            ."        return;\n"
            ."    }\n"
            ."    \$relative = str_replace('\\\\', '/', substr(\$class, strlen(\$prefix)));\n"
            ."    \$path = \$srcRoot.'/'.\$relative.'.php';\n"
            ."    if (is_file(\$path)) {\n"
            ."        require \$path;\n"
            ."    }\n"
            ."});\n";
        file_put_contents($dir.'/.hatfield/extensions/vendor/autoload.php', $autoload);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://192.168.2.38:9052/v1',
                        'api' => 'openai-completions',
                        'api_key' => 'dummy',
                        'completions_path' => '/chat/completions',
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'supports_thinking_levels' => true,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'thinking_level_map' => [
                                    'off' => '0', 'minimal' => '0', 'low' => '0', 'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => [
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                    'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension',
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => ['bash' => 'bash', 'write' => 'write', 'edit' => 'edit', 'read' => 'read'],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                    'observational_memory' => [
                        'storage' => [
                            'database' => '.hatfield/extensions-data/observational-memory/om.sqlite',
                        ],
                        'model' => 'llama_cpp_test/test',
                        'observer' => [
                            'context_window_ratio' => 0.65,
                        ],
                        'reflector' => [
                            'context_window_ratio' => 0.65,
                            'reflect_after_observation_tokens' => 40000,
                        ],
                        'pools' => [
                            'observations_max_tokens' => 30000,
                        ],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 8, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function seedOmDatabasePlaceholder(): void
    {
        $connection = $this->omDatabaseFactory()->connectAndMigrate($this->seedDbPath);
        $repo = new ObservationRepository($connection);
        $repo->commitChunkPartCoverage(
            coverageKey: 'cov-e2e',
            runId: 'seed-placeholder',
            boundaryKey: 'b1',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-e2e',
            partDigest: 'part-e2e',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $this->observationId,
                'content' => 'User prefers hyphenated commands for OM status and view',
                'content_hash' => hash('sha256', 'User prefers hyphenated commands for OM status and view'),
                'relevance' => 'high',
                'timestamp' => '2026-07-28 14:00',
                'token_count' => 16,
                'source_refs_json' => json_encode([['run_id' => 'seed-placeholder', 'seq' => 1]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T14:00:00+00:00',
        );
    }

    /**
     * Poll the visible pane only (no scrollback) until the predicate matches.
     *
     * OM command viewport output live above the editor; scrollback history would
     * false-pass removed widgets still present off-screen.
     */
    private function waitForViewport(
        TmuxPane $pane,
        callable $callback,
        float $timeout,
        string $message,
    ): string {
        $deadline = microtime(true) + $timeout;
        $lastCapture = '';
        while (microtime(true) < $deadline) {
            $lastCapture = $this->tmux->capturePlain($pane);
            if ($callback($lastCapture)) {
                return $lastCapture;
            }
            usleep(100_000);
        }

        throw new \RuntimeException(\sprintf("%s Timed out after %.1fs. Last viewport (%d lines):\n%s", $message, $timeout, substr_count($lastCapture, "\n") + 1, $lastCapture));
    }

    private function waitForLiveSessionId(): string
    {
        $sessionsDir = $this->testProjectDir.'/.hatfield/sessions';
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            if (is_dir($sessionsDir)) {
                $ids = array_values(array_filter(
                    scandir($sessionsDir) ?: [],
                    static fn (string $n): bool => !str_starts_with($n, '.') && is_dir($sessionsDir.'/'.$n),
                ));
                if ([] !== $ids) {
                    return $ids[0];
                }
            }
            usleep(50_000);
        }

        $this->fail('TUI session directory was not created after draft promotion submit');
    }

    private function rebindSeedToSession(string $sessionId): void
    {
        $connection = $this->omDatabaseFactory()->connect($this->seedDbPath);
        $connection->executeStatement('UPDATE om_observation SET run_id = ? WHERE run_id = ?', [$sessionId, 'seed-placeholder']);
        $connection->executeStatement('UPDATE om_coverage SET run_id = ? WHERE run_id = ?', [$sessionId, 'seed-placeholder']);
        $connection->executeStatement(
            'UPDATE om_observation SET source_refs_json = ? WHERE observation_id = ?',
            [json_encode([['run_id' => $sessionId, 'seq' => 1]], \JSON_THROW_ON_ERROR), $this->observationId],
        );
    }

    private function seedActivityForSession(string $sessionId): void
    {
        $connection = $this->omDatabaseFactory()->connectAndMigrate($this->seedDbPath);
        $repo = new ActivityRepository($connection);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $repo->upsert(
            $sessionId,
            'job-e2e-status',
            'reflector',
            2500,
            null,
            $now->format(\DateTimeInterface::ATOM),
        );
    }

    private function clearActivityForSession(string $sessionId): void
    {
        $connection = $this->omDatabaseFactory()->connect($this->seedDbPath);
        $repo = new ActivityRepository($connection);
        $repo->clear($sessionId, 'job-e2e-status');
    }

    private function assertFooterLineDoesNotContain(string $viewport, string $sessionId, string $needle): void
    {
        $footerNeedle = 'session '.$sessionId;
        $foundFooter = false;

        foreach (explode("\n", $viewport) as $line) {
            // ◆ alone is a stable footer anchor; session label is retained as a second path.
            $isFooterCandidate = str_contains($line, $footerNeedle) || str_contains($line, '◆');
            if (!$isFooterCandidate) {
                continue;
            }

            $foundFooter = true;
            $this->assertStringNotContainsString(
                $needle,
                $line,
                "keyed OM status must not appear on footer line:\n".$line,
            );
        }

        $this->assertTrue($foundFooter, 'Footer candidate line missing from viewport (session/◆ anchor)');
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts), $ansi);
    }
}
