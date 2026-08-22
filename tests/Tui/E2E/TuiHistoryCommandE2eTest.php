<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for the /history linear user-prompt picker.
 *
 * Selecting user prompt N positions context immediately before N and
 * fills the editor with N's original text. Forward history remains until
 * a later context-mutating action discards it.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiHistoryCommandE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    public function testHistorySelectPositionsBeforeSelectedPrompt(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandForFixtureChain(
                'tui-history-select-turn1-07c.json',
                'tui-history-select-turn2-07c.json',
            ),
            prefix: 'tui-history-select-07c',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->submitPrompt($pane, 'first-turn-marker-07c');
            $this->waitAssistantBlock($pane);
            $this->tmux->waitForCaptureContains($pane, 'FIRST_TURN_REPLY_07C', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL);

            $this->submitPrompt($pane, 'second-turn-marker-07c');
            $this->waitAssistantBlock($pane);
            $this->tmux->waitForCaptureContains($pane, 'SECOND_TURN_REPLY_07C', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL);

            // Bang is transcript content after turn 2. After selecting the second
            // user prompt, context is rebuilt before that prompt so bang + second
            // turn leave the transcript while first turn remains.
            $this->submitPrompt($pane, '!printf BANG_REWIND_07C');
            $this->tmux->waitForCaptureContains($pane, 'BANG_REWIND_07C', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL);
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '● idle'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Direct bang command never reached idle after BANG_REWIND_07C (tool events may still be in flight before history select)',
                history: 2000,
            );

            $this->runSlashCommand($pane, '/history');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Session history — Enter to edit prompt'),
                timeout: 10.0,
                message: 'History picker overlay did not open',
                history: 2000,
            );

            // User-prompt rows only. With tip after the shell turn, initial selection
            // is the last user prompt (second-turn). Confirm without navigation.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'second-turn-marker-07c'),
                timeout: 5.0,
                message: 'History picker should list the second-turn user prompt',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '● idle')
                    && !str_contains($cap, 'Session history — Enter to edit prompt'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'History picker did not close after Enter',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'first-turn-marker-07c')
                    && str_contains($cap, 'FIRST_TURN_REPLY_07C')
                    && !str_contains($cap, 'SECOND_TURN_REPLY_07C')
                    && !str_contains($cap, 'BANG_REWIND_07C'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Transcript did not rebuild before selected second prompt',
                history: 500,
            );

            $paneCapture = $this->tmux->capturePlain($pane);
            $this->assertStringContainsString('first-turn-marker-07c', $paneCapture,
                'Retained transcript should still show the first-turn user marker.');
            $this->assertStringContainsString('FIRST_TURN_REPLY_07C', $paneCapture,
                'Retained transcript should still show the first-turn assistant reply.');
            $this->assertStringContainsString('second-turn-marker-07c', $paneCapture,
                'Selected prompt text must be populated into the editor.');
            $this->assertStringNotContainsString('SECOND_TURN_REPLY_07C', $paneCapture,
                'Second-turn assistant reply must leave the transcript when positioned before that prompt.');
            $this->assertStringNotContainsString('BANG_REWIND_07C', $paneCapture,
                'Forward shell output must leave the transcript when positioned before the selected prompt.');

            $this->tmux->saveAnsiSnapshot($pane, 'history-select-before-prompt-07c');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'history-select-before-prompt-07c-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function submitPrompt(TmuxPane $pane, string $text): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, $text);
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function runSlashCommand(TmuxPane $pane, string $command): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        $this->tmux->sendLiteral($pane, $command);
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function waitAssistantBlock(TmuxPane $pane): void
    {
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '◇'),
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block (◇) did not appear',
            history: 2000,
        );
    }

    private function agentCommandForFixtureChain(string ...$fixtureFiles): string
    {
        $paths = [];
        foreach ($fixtureFiles as $file) {
            $path = $this->projectRoot.'/tests/Tui/E2E/fixtures/'.$file;
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
        $fixtureEnv = [] !== $paths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $paths)).' '
            : '';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-history-select-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-history');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
