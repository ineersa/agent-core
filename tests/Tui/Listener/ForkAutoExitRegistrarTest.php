<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\ForkAutoExitRegistrar;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Tui;

/**
 * Virtual proof for ForkAutoExitRegistrar's two-phase auto-exit barrier.
 *
 * Test thesis:
 *   ForkAutoExitRegistrar::register() attaches a tick handler that:
 *   1. No-ops for non-fork sessions.
 *   2. Ticks at normal rate until the run reaches a terminal state.
 *   3. Once terminal, waits for the .fork-finalized marker file.
 *   4. Stops the TUI when marker appears (or after timeout if missing).
 *
 * If the marker-wait has no timeout, a deadlocked controller/ForkRunFinalizer
 * would leave the fork child TUI spinning forever, preventing the parent
 * FORK-05 completion watcher from retrieving diagnostics.
 */
final class ForkAutoExitRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private const string MARKER_FILENAME = '.fork-finalized';

    /**
     * Non-fork sessions: tick handler is a no-op.
     */
    #[Test]
    public function nonForkSessionDoesNotStopTui(): void
    {
        $state = new TuiSessionState('session-1');
        $state->request = new StartRunRequest(
            prompt: 'hello',
            runId: 'run-1',
        );

        $tui = $this->createMock(Tui::class);
        $tui->expects($this->never())->method('stop');

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($this->createScreen())
            ->build();

        $registrar = new ForkAutoExitRegistrar();
        $registrar->register($context);

        $context->ticks->dispatch(new TickEvent());
        // expects('never')->method('stop') proves it.
    }

    /**
     * Fork mode with non-terminal run: returns null (keep polling at normal rate).
     */
    #[Test]
    public function forkModeNonTerminalDoesNotStopTui(): void
    {
        $state = new TuiSessionState('run-1');
        $state->request = new StartRunRequest(
            prompt: '',
            runId: 'run-1',
            options: ['fork_mode' => true, 'fork_result_dir' => '/tmp/fake-result'],
        );
        $state->activity = RunActivityStateEnum::Running;
        $state->handle = new RunHandle('run-1');

        $tui = $this->createMock(Tui::class);
        $tui->expects($this->never())->method('stop');

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($this->createScreen())
            ->build();

        $registrar = new ForkAutoExitRegistrar();
        $registrar->register($context);

        $result = $context->ticks->dispatch(new TickEvent());
        $this->assertNull($result, 'Non-terminal fork must return null (normal polling rate)');
    }

    /**
     * Fork mode, terminal run, marker already present: stops TUI immediately.
     */
    #[Test]
    public function forkModeTerminalWithMarkerStopsTui(): void
    {
        $tmpDir = $this->createTmpDir();
        file_put_contents($tmpDir.'/'.self::MARKER_FILENAME, '{}');

        try {
            $state = new TuiSessionState('run-1');
            $state->request = new StartRunRequest(
                prompt: '',
                runId: 'run-1',
                options: ['fork_mode' => true, 'fork_result_dir' => $tmpDir],
            );
            $state->activity = RunActivityStateEnum::Completed;
            $state->handle = new RunHandle('run-1');

            $tui = $this->createMock(Tui::class);
            $tui->expects($this->once())->method('stop');

            $context = $this->buildTuiContext()
                ->withTui($tui)
                ->withState($state)
                ->withScreen($this->createScreen())
                ->build();

            $registrar = new ForkAutoExitRegistrar();
            $registrar->register($context);

            $result = $context->ticks->dispatch(new TickEvent());
            $this->assertTrue($result, 'Terminal fork with marker must return true');
        } finally {
            $this->cleanupTmpDir($tmpDir);
        }
    }

    /**
     * Fork mode, terminal run, marker not yet present: returns true (full-speed polling).
     */
    #[Test]
    public function forkModeTerminalWithoutMarkerKeepsPolling(): void
    {
        $tmpDir = $this->createTmpDir();

        try {
            $state = new TuiSessionState('run-1');
            $state->request = new StartRunRequest(
                prompt: '',
                runId: 'run-1',
                options: ['fork_mode' => true, 'fork_result_dir' => $tmpDir],
            );
            $state->activity = RunActivityStateEnum::Completed;
            $state->handle = new RunHandle('run-1');

            $tui = $this->createMock(Tui::class);
            $tui->expects($this->never())->method('stop');

            $context = $this->buildTuiContext()
                ->withTui($tui)
                ->withState($state)
                ->withScreen($this->createScreen())
                ->build();

            $registrar = new ForkAutoExitRegistrar();
            $registrar->register($context);

            // First tick: terminal state seen, enters marker-wait phase.
            $result = $context->ticks->dispatch(new TickEvent());
            $this->assertTrue($result, 'Must return true while waiting for marker');

            // Second tick: marker still absent, still waiting.
            $result = $context->ticks->dispatch(new TickEvent());
            $this->assertTrue($result, 'Second tick must also return true when marker absent');
        } finally {
            $this->cleanupTmpDir($tmpDir);
        }
    }

    /**
     * Fork mode, terminal run, marker appears between ticks: second tick stops TUI.
     */
    #[Test]
    public function forkModeTerminalWithDelayedMarkerStopsOnSecondTick(): void
    {
        $tmpDir = $this->createTmpDir();
        $markerPath = $tmpDir.'/'.self::MARKER_FILENAME;

        try {
            $state = new TuiSessionState('run-1');
            $state->request = new StartRunRequest(
                prompt: '',
                runId: 'run-1',
                options: ['fork_mode' => true, 'fork_result_dir' => $tmpDir],
            );
            $state->activity = RunActivityStateEnum::Completed;
            $state->handle = new RunHandle('run-1');

            $tui = $this->createMock(Tui::class);
            $tui->expects($this->once())->method('stop');

            $context = $this->buildTuiContext()
                ->withTui($tui)
                ->withState($state)
                ->withScreen($this->createScreen())
                ->build();

            $registrar = new ForkAutoExitRegistrar();
            $registrar->register($context);

            // First tick: enters marker-wait phase, no marker yet.
            $context->ticks->dispatch(new TickEvent());

            // Create marker between ticks (simulates ForkRunFinalizer completing finalization).
            file_put_contents($markerPath, '{}');

            // Second tick: marker found, should stop TUI.
            $result = $context->ticks->dispatch(new TickEvent());
            $this->assertTrue($result, 'Must return true when marker appears');
        } finally {
            $this->cleanupTmpDir($tmpDir);
        }
    }

    /**
     * Fork mode, terminal run, no result dir: stops immediately (graceful degradation).
     */
    #[Test]
    public function forkModeTerminalWithoutResultDirStopsImmediately(): void
    {
        $state = new TuiSessionState('run-1');
        $state->request = new StartRunRequest(
            prompt: '',
            runId: 'run-1',
            options: ['fork_mode' => true],
        );
        $state->activity = RunActivityStateEnum::Completed;
        $state->handle = new RunHandle('run-1');

        $tui = $this->createMock(Tui::class);
        $tui->expects($this->once())->method('stop');

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($this->createScreen())
            ->build();

        $registrar = new ForkAutoExitRegistrar();
        $registrar->register($context);

        $result = $context->ticks->dispatch(new TickEvent());
        $this->assertTrue($result, 'Terminal fork without result dir must stop TUI');
    }

    /**
     * Fork mode, no run handle yet: returns null (keep polling at normal rate).
     */
    #[Test]
    public function forkModeWithoutHandleReturnsNull(): void
    {
        $state = new TuiSessionState('run-1');
        $state->request = new StartRunRequest(
            prompt: '',
            runId: 'run-1',
            options: ['fork_mode' => true],
        );

        $tui = $this->createMock(Tui::class);
        $tui->expects($this->never())->method('stop');

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($this->createScreen())
            ->build();

        $registrar = new ForkAutoExitRegistrar();
        $registrar->register($context);

        $result = $context->ticks->dispatch(new TickEvent());
        $this->assertNull($result, 'Without handle, must return null (normal polling rate)');
    }

    // ── Helpers ──

    private function createScreen(): ChatScreen
    {
        return new ChatScreen(
            theme: new DefaultTheme(new ThemePalette(
                name: 'virtual-test',
                colors: [
                    'foreground' => '#000',
                    'background' => '#fff',
                    'accent' => '#00f',
                    'success' => '#0f0',
                    'warning' => '#ff0',
                    'error' => '#f00',
                    'muted' => '#888',
                ],
            )),
            sessionId: 'test-session',
            promptEditor: new PromptEditor(),
        );
    }

    private function createTmpDir(): string
    {
        $dir = sys_get_temp_dir().'/fork-auto-exit-test-'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function cleanupTmpDir(string $dir): void
    {
        $marker = $dir.'/'.self::MARKER_FILENAME;
        if (is_file($marker)) {
            @unlink($marker);
        }
        @rmdir($dir);
    }
}
