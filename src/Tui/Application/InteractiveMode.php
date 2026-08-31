<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProcessReloadState;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SlashCommandCatalogRegistrar;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEndReasonEnum;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

/**
 * Application-level TUI entry point.
 *
 * Receives an AgentSessionClient from the CLI command and runs the interactive
 * terminal UI using Symfony TUI's event loop (Revolt-powered).
 *
 * Supports session persistence via HatfieldSessionStore:
 *  - New sessions create .hatfield/sessions/<session-id>/
 *  - Resume reloads transcript and events from disk
 *  - All user submissions and runtime events are appended in real time
 *
 * Listener wiring is done via DI-tagged TuiListenerRegistrar services;
 * the registrars are stateless and receive a per-run TuiRuntimeContext
 * carrying the per-session service scope ({@see TuiSessionServices}).
 *
 * ## Session switch lifecycle
 *
 * When a TUI slash command (/new, /resume) calls
 * {@see TuiSessionSwitchService} to request a session switch, the
 * service cancels the current run and calls {@see Tui::stop()}.  Each
 * loop iteration then composes a fresh per-session service scope via
 * {@see TuiSessionCompositionFactory} (controllers, history, command
 * registry, switch service, parent/child transcript projectors) and
 * rebuilds Tui/TuiSessionState/ChatScreen objects for the target
 * session — all within the same CLI process.
 *
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final readonly class InteractiveMode
{
    /**
     * @param iterable<TuiListenerRegistrar>         $listenerRegistrars
     * @param iterable<SlashCommandCatalogRegistrar> $catalogRegistrars
     */
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private ThemeRegistry $themeRegistry,
        private SessionInitializer $sessionInit,
        private iterable $listenerRegistrars,
        private PromptEditor $promptEditor,
        private TranscriptBlockFactory $blockFactory,
        private LoggerInterface $logger,
        private AppConfig $appConfig,
        private TranscriptDisplayConfigMapper $transcriptConfigMapper,
        private HistoryProviderInterface $historyProvider,
        private SlashCommandCatalog $commandCatalog,
        private iterable $catalogRegistrars,
        private TuiSessionCompositionFactory $compositionFactory,
    ) {
    }

    /**
     * Run the interactive TUI for a given session client.
     *
     * @param AgentSessionClient   $client    The runtime session client
     * @param StartRunRequest|null $request   Optional pre-configured start request (from AgentCommand)
     * @param TuiTheme|null        $theme     Pre-resolved theme (for testing)
     * @param string               $sessionId Existing session ID to resume; empty = new session
     *
     * @return int Console exit code
     */
    public function run(
        AgentSessionClient $client,
        ?StartRunRequest $request = null,
        ?TuiTheme $theme = null,
        string $sessionId = '',
    ): int {
        // ── Install signal handlers for graceful process cleanup ──
        // On SIGTERM/SIGINT: log a structured diagnostic event before
        // terminating so the crash trail is visible. bare exit(0) still
        // triggers PHP's shutdown sequence (destructors, __destruct,
        // register_shutdown_function callbacks), which run the process
        // client cleanup and stop the controller subprocess.
        // SIGKILL: uncatchable; orphaned consumers are reaped on next controller startup.
        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $logger = $this->logger;
            pcntl_signal(\SIGTERM, static function () use ($logger): void {
                $logger->info('TUI received SIGTERM — terminating', [
                    'component' => 'InteractiveMode',
                    'event_type' => 'signal_exit',
                    'signal' => 'SIGTERM',
                ]);
                exit(0);
            });
            pcntl_signal(\SIGINT, static function () use ($logger): void {
                $logger->info('TUI received SIGINT — terminating', [
                    'component' => 'InteractiveMode',
                    'event_type' => 'signal_exit',
                    'signal' => 'SIGINT',
                ]);
                exit(0);
            });
        }

        $theme ??= new DefaultTheme($this->themeRegistry->getOrThrow($this->appConfig->tui->theme));

        // ── Session switch loop ──
        // Each iteration builds fresh TUI/session objects for the
        // current target.  A session switch (via TuiSessionSwitchService)
        // stops the event loop; we consume the pending target and loop.
        //
        // $tui->run() blocks via Revolt fiber suspension (not a CPU
        // spin): it suspends the current fiber and resumes only when
        // $tui->stop() is called or the user quits.  The while(true)
        // therefore iterates once per session, not infinitely.
        $targetSessionId = $sessionId;
        $targetRequest = $request;
        $isDraft = ('' === $sessionId && null === $request);
        // Track the session id from the previous iteration so the
        // next start/resume/draft-start lifecycle event can carry it
        // as previousSessionId — useful for extensions tracking which
        // session the user switched from.  Null for the very first
        // iteration (no prior session).
        $previousSessionIdForLifecycle = null;

        // Set true once per session switch and intentionally never reset
        // to false — every later switch iteration also needs a fresh
        // screen before the new TUI paints.  Normal quit exits the loop
        // without consuming a switch target, so the flag never fires on
        // shutdown.
        $needsTerminalClear = false;

        // Map the immutable TranscriptDisplayConfig once before the session
        // loop; it is derived from Hatfield config which does not change
        // within a TUI lifetime. Each new session still gets a fresh mutable
        // TranscriptDisplayState initialized from the immutable config.
        $displayConfig = $this->transcriptConfigMapper->map($this->appConfig->tui->transcript);

        // ── Seed the process-scoped slash command catalog once ──
        // Command metadata/aliases/help data is registered exactly once per
        // process, in tag-priority order (real commands before the -100
        // prompt-template registrar so templates skip name collisions).  Each
        // session iteration then binds only its own handlers via the fresh
        // per-session SlashCommandRegistry.
        foreach ($this->catalogRegistrars as $catalogRegistrar) {
            $catalogRegistrar->registerCatalog($this->commandCatalog);
        }

        while (true) {
            // ── Initialize session state ──
            if ($isDraft) {
                $state = $this->sessionInit->initializeDraft($targetRequest);
            } elseif ('' !== $targetSessionId) {
                $state = $this->sessionInit->initialize($targetSessionId, $targetRequest);
            } else {
                // Fresh session — pass through any initial request so
                // `bin/console agent --prompt ...` starts a run immediately.
                $state = $this->sessionInit->initialize('', $targetRequest);
            }

            // ── Initialize per-session transcript display state ──
            // The immutable config is mapped once above.  Each session
            // (draft/resume/fresh) gets its own mutable display state
            // initialized from the config's previewsExpandedByDefault.
            $state->transcriptDisplayState = new TranscriptDisplayState(
                previewableBlocksExpanded: $displayConfig->previewsExpandedByDefault,
            );

            // ── Build screen and mount widget tree ──
            $tui = new Tui();
            $screen = new ChatScreen(
                $theme,
                $state->sessionId,
                $this->promptEditor,
                $displayConfig,
                $state->transcriptDisplayState,
            );
            $screen->mount($tui);

            // Apply Ctrl+J as portable newline, overriding the default new_line
            // key list.  Both ctrl+j and shift+enter are listed so the default
            // Shift+Enter behavior is preserved alongside the new portable key.
            $this->promptEditor->setKeybindings(new Keybindings([
                'new_line' => ['ctrl+j', 'shift+enter'],
            ]));

            // ── Compose the per-session service scope ──
            // Fresh controllers, question/history state, command registry,
            // switch service, and parent/child transcript projectors — nothing
            // is reused from the previous iteration.
            $services = $this->compositionFactory->create($tui, $screen, $state, $client);

            // ── Build initial transcript with the scope's parent applier ──
            // Resume replay and subsequent parent polling share the same
            // session-owned projector/projection state.
            $state->replaceTranscript(
                $this->sessionInit->buildInitialTranscript($state, $services->parentEventApplier),
            );

            // Seed session-scoped prompt history from the rebuilt transcript
            // (UserMessage blocks); grown by SubmitListener on each submit.
            $services->promptHistory->seedFrom($state->transcript);

            // Set initial transcript
            $screen->setTranscriptBlocks($state->transcript);

            // ── Force a full-screen clear on session switches ──
            //
            // requestRender(true) resets ScreenWriter's previous-dirty
            // tracking so the first render clears the screen atomically
            // inside TUI synchronized output.  The old TUI has already
            // restored the terminal to its initial state via stop() so
            // no separate stty manipulation or out-of-band escape writes
            // are needed.
            if ($needsTerminalClear) {
                $tui->requestRender(true);
            }

            // ── Start or resume the run ──
            $this->startOrResumeRun($client, $state, $screen);

            // Passive resume does not poll runtime events immediately; clear any
            // optimistic Working... left from a prior in-session submit so terminal
            // cancelled/completed/failed sessions do not show ◐ Working... until tick.
            if ($state->resuming && !$state->activity->isActive()) {
                $screen->setWorkingMessage('');
            }

            // ── Register listeners (DI-driven, stateless registrars) ──
            $ticks = new TuiTickDispatcher();
            $lifecycle = new TuiSessionLifecycleDispatcher();

            $context = new TuiRuntimeContext(
                tui: $tui,
                client: $client,
                state: $state,
                screen: $screen,
                sessionStore: $this->sessionStore,
                ticks: $ticks,
                switch: $services->switch,
                lifecycle: $lifecycle,
                historyProvider: $this->historyProvider,
                sessionServices: $services,
            );

            foreach ($this->listenerRegistrars as $registrar) {
                $registrar->register($context);
            }

            // Install single Symfony tick callback that multiplexes to all registered handlers
            $tui->onTick(static fn (TickEvent $event): ?bool => $ticks->dispatch($event));

            // Also register slot input handlers as native InputEvent listeners
            $screen->registerSlotInputListeners();

            // ── Dispatch session lifecycle start event ──
            // Must happen AFTER listener registrars have run so that
            // subscribers to $context->lifecycle are already wired.
            $this->dispatchSessionLifecycleStart(
                $lifecycle,
                $state,
                $isDraft,
                $targetSessionId,
                $previousSessionIdForLifecycle,
            );

            $tui->setFocus($screen->editorWidget());
            $tui->run();

            // ── Determine exit reason and dispatch session ended ──
            $reloadIntent = $services->switch->consumePendingReload();
            $switchTarget = $services->switch->consumePendingSwitch();
            $endReason = (null !== $reloadIntent)
                ? TuiSessionLifecycleEndReasonEnum::Reload
                : ((null !== $switchTarget)
                    ? TuiSessionLifecycleEndReasonEnum::Switch
                    : TuiSessionLifecycleEndReasonEnum::Quit);
            $lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
                type: TuiSessionLifecycleEventTypeEnum::SessionEnded,
                sessionId: $state->sessionId,
                isDraft: $isDraft,
                resuming: $state->resuming,
                endReason: $endReason,
            ));

            // ── Full-process settings reload (/reload) ──
            //
            // Not a same-process session switch: hand the typed intent to the
            // process-global relay (it must survive container recreation),
            // shut the session client down synchronously (controller +
            // consumers stopped before a fresh controller is spawned), and
            // return the dedicated exit code so bin/console's outer bootstrap
            // loop rebuilds kernel + container and relaunches this session.
            // The terminal was already restored by Tui::run()'s finally.
            // Clear the visible screen + scrollback here (same as /resume)
            // so the user sees a blank terminal before the fresh boot redraws.
            if (null !== $reloadIntent) {
                $this->clearScreenForNextSession($tui->getTerminal());
                ProcessReloadState::set($reloadIntent);
                $client->shutdown();

                return ProcessReloadState::EXIT_CODE;
            }

            // ── After event loop exits: check for pending switch ──
            if (null !== $switchTarget) {
                // Clear before the next same-process session iteration so the
                // user sees a blank screen while the next TUI boots. See
                // clearScreenForNextSession() for the escape-sequence rationale.
                //
                // This does NOT affect picker-open rendering (triggered via
                // PickerOverlay::mount()) — that code path never reaches this
                // write because $switchTarget is consumed AFTER the picker
                // callback runs.  The picker-open flicker fix (b50cb2540) is
                // preserved unchanged.
                $this->clearScreenForNextSession($tui->getTerminal());

                // Hard session boundary: stop the previous controller/consumers
                // and clear client-local buffers/child observation before the
                // next draft or resume attaches. /new must not keep the old
                // runtime alive under a sessionless draft; /resume then
                // attach() starts a fresh session-scoped controller.
                $client->shutdown();

                $needsTerminalClear = true;
                // Record the session id we're leaving so the next
                // iteration's lifecycle start event can reference
                // it as previousSessionId.
                $previousSessionIdForLifecycle = ('' !== $state->sessionId) ? $state->sessionId : null;
                if ($switchTarget->isDraft) {
                    $isDraft = true;
                    $targetSessionId = '';
                    $targetRequest = $switchTarget->request;
                } else {
                    $isDraft = false;
                    $targetSessionId = $switchTarget->sessionId;
                    $targetRequest = null;
                }
            } else {
                // ── Normal exit (Ctrl+D) — cursor cleanup ──
                //
                // Terminal::stop() leaves the cursor wherever the old TUI
                // last positioned it (typically the editor area at the
                // bottom).  Write a carriage-return + newline so the shell
                // prompt appears at the beginning of the next line below
                // the TUI content, rather than at the previous cursor
                // position.  This eliminates the "cursor jumps to bottom"
                // symptom the user reported.
                $tui->getTerminal()->write("\r\n");
                break; // Normal exit — no pending switch
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Blank the visible screen + scrollback before the next TUI starts.
     *
     * Used by both /resume (same-process switch) and /reload (outer-loop
     * rebuild) after Tui::run()'s finally has restored cooked mode. Instant
     * visual feedback: screen blanks and the cursor homes, instead of sitting
     * wherever the old TUI left it (typically the editor near the bottom).
     *
     *   clearScreen() — \x1b[2J + \x1b[H (visible clear + home)
     *   write("\x1b[3J") — scrollback-only clear (no native API)
     *
     * These sequences are processed by the terminal emulator regardless of
     * terminal mode; they are NOT wrapped in DECSET 2026 synchronised-output
     * markers because tmux does not universally support them and would render
     * them as visible characters.
     *
     * For /resume the next TUI's first render also performs
     * fullRender(clear=true) via ScreenWriter, but that happens inside the
     * new event loop and provides no perceptible gap. The direct write here
     * provides the gap the user perceived as "screen blanked for ~0.5s".
     */
    private function clearScreenForNextSession(TerminalInterface $terminal): void
    {
        $terminal->clearScreen();
        $terminal->write("\x1b[3J");
    }

    /**
     * Dispatch the session lifecycle startup event.
     *
     * Must be called AFTER listener registrars have wired their
     * subscriptions to $lifecycle, but BEFORE the TUI event loop
     * starts so subscribers can initialise state synchronously.
     *
     * @param string|null $previousSessionId session ID of the iteration just
     *                                       ended, or null for the first iteration
     */
    private function dispatchSessionLifecycleStart(
        TuiSessionLifecycleDispatcher $lifecycle,
        TuiSessionState $state,
        bool $isDraft,
        string $targetSessionId,
        ?string $previousSessionId,
    ): void {
        if ($isDraft) {
            $lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
                type: TuiSessionLifecycleEventTypeEnum::SessionDraftStarted,
                sessionId: '',
                isDraft: true,
                resuming: false,
                previousSessionId: $previousSessionId,
            ));
        } elseif ('' !== $targetSessionId) {
            $lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
                type: TuiSessionLifecycleEventTypeEnum::SessionResumed,
                sessionId: $state->sessionId,
                isDraft: false,
                resuming: true,
                previousSessionId: $previousSessionId,
            ));
        } else {
            $lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
                type: TuiSessionLifecycleEventTypeEnum::SessionStarted,
                sessionId: $state->sessionId,
                isDraft: false,
                resuming: false,
                previousSessionId: $previousSessionId,
            ));
        }
    }

    /**
     * Start an initial run or reconnect to an existing one.
     */
    private function startOrResumeRun(
        AgentSessionClient $client,
        TuiSessionState $state,
        ChatScreen $screen,
    ): void {
        // Draft sessions (empty sessionId) never start a run here —
        // the first message submitted later promotes them to a real session.
        if ('' === $state->sessionId) {
            return;
        }

        if (null !== $state->request && '' !== $state->request->prompt) {
            try {
                $state->handle = $client->start($state->request);
                $this->sessionStore->updateMetadata($state->sessionId, [
                    'run_id' => $state->handle->runId,
                    'prompt' => $state->request->prompt,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to start initial run', [
                    'exception' => $e,
                    'session_id' => $state->sessionId,
                ]);
                $state->appendTranscriptBlock($this->blockFactory->error(
                    runId: $state->sessionId,
                    text: 'Runtime error: '.$e->getMessage(),
                    seq: \count($state->transcript) + 1,
                ));
            }
            $screen->setTranscriptBlocks($state->transcript);
        } elseif ($state->resuming) {
            $session = $this->sessionStore->findSession($state->sessionId);
            if (null !== $session && $session->id > 0) {
                $existingRunId = (string) $session->id;
                try {
                    $state->handle = $client->attach($existingRunId);
                    $state->appendTranscriptBlock($this->blockFactory->system(
                        runId: $state->sessionId,
                        text: \sprintf('Resumed run %s', $existingRunId),
                        seq: \count($state->transcript) + 1,
                        style: 'muted',
                        category: 'lifecycle',
                    ));
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to resume run', [
                        'exception' => $e,
                        'run_id' => $existingRunId,
                    ]);
                    $state->appendTranscriptBlock($this->blockFactory->system(
                        runId: $state->sessionId,
                        text: 'Could not resume run — starting fresh.',
                        seq: \count($state->transcript) + 1,
                        style: 'warning',
                    ));
                }
                $screen->setTranscriptBlocks($state->transcript);
            }
        }
    }
}
