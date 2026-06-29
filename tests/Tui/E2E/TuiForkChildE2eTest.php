<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal TmuxHarness E2E proof for the fork child lifecycle.
 *
 * Test thesis:
 *   Running source bin/console agent --fork in a real tmux pane with
 *   replay/no live LLM must: boot the normal process-transport TUI fork
 *   child, dispatch the empty-prompt fork start to controller, finalize
 *   after a replayed valid handoff, wait for .fork-finalized, auto-exit,
 *   print ---FORK-RESULT-START---, and leave deterministic artifacts
 *   (handoff.md, fork-metadata.json, .fork-finalized) under the fork
 *   result dir.
 *
 * This exercises the full fork child lifecycle:
 *   AgentCommand::runForkTui() → InteractiveMode → process transport
 *   → controller ForkControllerStartService → AgentRunner → LLM replay
 *   → ForkRunTerminalWatcher handoff validation/artifact writing
 *   → ForkAutoExitRegistrar marker barrier → TUI stop → exit sentinel.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiForkChildE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Fork child lifecycle E2E proof: boot, handoff, finalization, auto-exit.
     *
     * Starts agent --fork in a detached tmux pane with a replay fixture,
     * waits for the ---FORK-RESULT-START--- sentinel, then asserts that
     * the fork result directory contains the expected artifacts.
     */
    public function testForkChildLifecycle(): void
    {
        // ── Prepare fork input artifacts ──
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $forkRunId = 'fork-'.bin2hex(random_bytes(4));
        $childRunId = 'child-'.bin2hex(random_bytes(4));
        $resultDir = $this->testProjectDir.'/fork-result';

        // Canonical artifact path where ForkRunTerminalWatcher writes handoff
        // via AgentArtifactRegistry::writeHandoff().
        // <sessionsBase>/<parentRunId>/artifacts/agents/<artifactId>/handoff.md
        $handoffPath = $this->testProjectDir
            .'/.hatfield/sessions/'.$parentRunId
            .'/artifacts/agents/'.$forkRunId
            .'/handoff.md';

        $snapshotPath = $this->createForkSnapshotFile($parentRunId, $forkRunId, $childRunId);

        // ── Start fork child in tmux ──
        $pane = $this->tmux->startDetached(
            command: $this->forkCommand($snapshotPath, $resultDir, $parentRunId, $forkRunId, $childRunId),
            prefix: 'tui-fork-child',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // ── Wait for the sentinel exit marker ──
        // The sentinel is written by runForkTui() after the TUI auto-exits.
        // If found, the fork child completed the full lifecycle.
        try {
            $this->tmux->waitForHistoryContains(
                pane: $pane,
                needle: '---FORK-RESULT-START---',
                timeout: 45.0,
                history: 2000,
            );
        } catch (\RuntimeException $e) {
            $this->saveAnsiSnapshot($pane, 'fork-child-no-sentinel');
            // Try to capture any diagnostic output before failing.
            $capture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::fail(\sprintf(
                "Fork child sentinel not found within 45s.\nPane capture:\n%s\n\nTmux error:\n%s",
                $capture,
                $e->getMessage(),
            ));
        }

        // ── Assert exit payload has expected shape ──
        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);
        self::assertStringContainsString(
            '---FORK-RESULT-START---',
            $capture,
            'Fork exit sentinel start marker missing in pane output',
        );
        self::assertStringContainsString(
            '---FORK-RESULT-END---',
            $capture,
            'Fork exit sentinel end marker missing in pane output',
        );
        self::assertStringContainsString(
            '"status":"exited"',
            $capture,
            'Fork exit payload should contain exited status',
        );
        self::assertStringContainsString(
            '"child_run_id":"'.$childRunId.'"',
            $capture,
            'Fork exit payload should contain child_run_id',
        );
        self::assertStringContainsString(
            '"artifact_id":"'.$forkRunId.'"',
            $capture,
            'Fork exit payload should contain artifact_id (fork_run_id)',
        );

        // ── Assert result directory artifacts ──
        $this->assertFileExists($resultDir.'/fork-metadata.json', 'fork-metadata.json must exist in result directory');

        $this->assertFileExists($resultDir.'/.fork-finalized', '.fork-finalized marker must exist in result directory');

        // handoff.md is written by AgentArtifactRegistry::writeHandoff() to
        // the canonical session artifact path, NOT the result directory.
        // The result dir contains fork-metadata.json and .fork-finalized only.
        $this->assertFileExists($handoffPath, 'handoff.md must exist in artifact registry path');

        // Verify handoff.md is NOT in the result dir (it lives in sessions).
        // This confirms the separation of concerns: ForkRunTerminalWatcher uses
        // AgentArtifactRegistry for the canonical handoff and writes runtime
        // metadata separately to the result dir.
        self::assertFileDoesNotExist($resultDir.'/handoff.md');

        // ── Validate fork-metadata.json contents ──
        $metadata = json_decode(
            (string) file_get_contents($resultDir.'/fork-metadata.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $this->assertSame($parentRunId, $metadata['parent_run_id'] ?? null, 'fork-metadata parent_run_id mismatch');
        $this->assertSame($forkRunId, $metadata['fork_run_id'] ?? null, 'fork-metadata fork_run_id mismatch');
        $this->assertSame($childRunId, $metadata['child_run_id'] ?? null, 'fork-metadata child_run_id mismatch');
        $this->assertSame('completed', $metadata['status'] ?? null, 'fork-metadata status must be "completed"');
        $this->assertSame('middle', $metadata['level'] ?? null, 'fork-metadata level must be "middle"');
        $this->assertSame('llama_cpp_test/test', $metadata['resolved_model'] ?? null, 'fork-metadata resolved_model must match snapshot');
        $this->assertArrayHasKey('completed_at', $metadata, 'fork-metadata must have completed_at timestamp');
        $this->assertSame(0, $metadata['validation_attempts'] ?? -1, 'fork-metadata validation_attempts must be 0 (valid on first try)');
        // The ?? operator treats null as not-set, so we check separately.
        $this->assertArrayHasKey('error', $metadata, 'fork-metadata must have error key');
        $this->assertNull($metadata['error'], 'fork-metadata error must be null for successful completion');

        // ── Validate handoff.md contents ──
        $handoff = (string) file_get_contents($handoffPath);
        $this->assertStringContainsString('## 11. Final handoff', $handoff, 'handoff.md must contain the final handoff section');
        $this->assertStringContainsString('No filesystem changes made', $handoff, 'handoff.md must have filesystem-changes statement in section 1');

        // ── Validate .fork-finalized marker ──
        $finalized = json_decode(
            (string) file_get_contents($resultDir.'/.fork-finalized'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $this->assertArrayHasKey('finalized_at', $finalized, '.fork-finalized must contain finalized_at timestamp');

        // Save a success snapshot for inspection.
        $this->saveAnsiSnapshot($pane, 'fork-child-completed');
    }

    // ── Fixture helpers ─────────────────────────────────────

    /**
     * Create a minimal fork snapshot JSON file.
     *
     * Written manually in the format expected by ForkSessionSnapshotSerializer
     * (camelCase keys, AgentMessage JSON structure, BackedEnum value for level).
     */
    private function createForkSnapshotFile(string $parentRunId, string $forkRunId, string $childRunId): string
    {
        $path = $this->testProjectDir.'/fork-snapshot.json';

        $snapshot = [
            'messages' => [],
            'forkSystemPromptAppend' => 'FORK CHILD TEST MODE: You are a fork child. Complete the assigned task exactly and produce a valid handoff report with all required sections.',
            'forkTaskUserMessage' => \sprintf(
                'Task: Produce a valid fork handoff report with the 11 required sections.'.
                ' Include mandatory sections ## 1. Result / status, ## 5. Changes made, and ## 11. Final handoff.'.
                ' In section 1 state "No filesystem changes made."'.
                ' This is a fork child E2E test. Parent run: %s, fork: %s, child: %s.',
                $parentRunId,
                $forkRunId,
                $childRunId,
            ),
            'level' => 'middle',
            'resolvedModel' => 'llama_cpp_test/test',
        ];

        $written = file_put_contents(
            $path,
            json_encode($snapshot, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
        if (false === $written) {
            throw new \RuntimeException('Failed to write fork snapshot file: '.$path);
        }

        return $path;
    }

    private function forkCommand(
        string $snapshotPath,
        string $resultDir,
        string $parentRunId,
        string $forkRunId,
        string $childRunId,
    ): string {
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        $fixturePath = __DIR__.'/fixtures/tui-fork-child-handoff.json';
        \assert(\is_file($fixturePath), 'Replay fixture must exist at '.$fixturePath);

        $fixtureEnv = 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' ';

        $dbPath = 'app_test-tui-fork-'.bin2hex(random_bytes(4)).'.sqlite';

        // Task description wrapped in single quotes for shell safety.
        $task = 'Produce valid handoff.';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s HATFIELD_FORK=1 %s%s %s agent'
                .' --fork'
                .' --snapshot=%s'
                .' --result-dir=%s'
                .' --parent-run-id=%s'
                .' --fork-run-id=%s'
                .' --child-run-id=%s'
                .' --task=%s'
                .' --level=middle'
                .' --model=llama_cpp_test/test'
                .' --reasoning=off'
                .' --tools-excluded=bash'
                .' --no-skills'
                .' 2>&1; echo __FORK_EXIT__=$?; sleep 5',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg($php),
            \escapeshellarg($script),
            \escapeshellarg($snapshotPath),
            \escapeshellarg($resultDir),
            \escapeshellarg($parentRunId),
            \escapeshellarg($forkRunId),
            \escapeshellarg($childRunId),
            \escapeshellarg($task),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
        @\mkdir($dir.'/.hatfield', 0o777, true);

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
                                    'off' => '0',
                                    'minimal' => '0',
                                    'low' => '0',
                                    'medium' => '0',
                                    'high' => '0',
                                    'xhigh' => '0',
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
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => [
                            'bash' => 'bash',
                            'write' => 'write',
                            'edit' => 'edit',
                            'read' => 'read',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        // Also write for the HOME dir.
        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        // Plant AGENTS.md and a minimal skill to satisfy context builders.
        @\mkdir($dir.'/.agents', 0o777, true);
        @\mkdir($dir.'/.agents/skills/e2e-fork', 0o777, true);
        \file_put_contents(
            $dir.'/.agents/skills/e2e-fork/SKILL.md',
            "---\nname: e2e-fork\ndescription: fork child E2E test skill\n---\n",
        );

        return $dir;
    }

    // ── Diagnostics ────────────────────────────────────────

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = \date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
