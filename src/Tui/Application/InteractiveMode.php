<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Tui\Event\TickEvent;
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
 * the registrars are stateless and receive a per-run TuiRuntimeContext.
 *
 * ## Session switch lifecycle
 *
 * When a TUI slash command (/new, /resume) calls
 * {@see TuiSessionSwitchService} to request a session switch, the
 * service cancels the current run, resets stateful singletons, and
 * calls {@see Tui::stop()}.  InteractiveMode then rebuilds fresh
 * Tui/TuiSessionState/ChatScreen objects for the target session and
 * re-enters the event loop — all within the same CLI process.
 *
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final readonly class InteractiveMode
{
    /**
     * @param iterable<TuiListenerRegistrar> $listenerRegistrars
     */
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private ThemeFactory $themeFactory,
        private SessionInitializer $sessionInit,
        private iterable $listenerRegistrars,
        private PromptEditor $promptEditor,
        private TranscriptBlockFactory $blockFactory,
        private LoggerInterface $logger,
        private TuiSessionSwitchService $switchService,
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
        // SIGTERM: graceful exit → PHP shutdown → __destruct → stopProcess() kills controller
        // SIGINT:  same, but only if the TUI isn't handling Ctrl+C internally (CtrlCInputInterceptor)
        // SIGKILL: uncatchable; orphaned consumers are reaped on next controller startup
        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGTERM, static function (): void {
                exit(0);
            });
            pcntl_signal(\SIGINT, static function (): void {
                exit(0);
            });
        }

        $theme = $this->themeFactory->create($theme);

        // ── Session switch loop ──
        // Each iteration builds fresh TUI/session objects for the
        // current target.  A session switch (via SessionSwitchService)
        // stops the event loop; we consume the pending target and loop.
        $targetSessionId = $sessionId;
        $targetRequest = $request;
        $isDraft = ('' === $sessionId && null === $request);

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
            $state->transcript = $this->sessionInit->buildInitialTranscript($state);

            // ── Build screen and mount widget tree ──
            $tui = new Tui();
            $screen = new ChatScreen($theme, $state->sessionId, $this->promptEditor);
            $screen->mount($tui);

            // Set initial transcript
            $screen->setTranscriptBlocks($state->transcript);

            // ── Start or resume the run ──
            $this->startOrResumeRun($client, $state, $screen);

            // ── Register listeners (DI-driven, stateless registrars) ──
            $ticks = new TuiTickDispatcher();

            // Bind switch service to this iteration's objects
            $this->switchService->bindForIteration($tui, $client, $state);

            $context = new TuiRuntimeContext(
                tui: $tui,
                client: $client,
                state: $state,
                screen: $screen,
                sessionStore: $this->sessionStore,
                ticks: $ticks,
                switch: $this->switchService,
            );

            foreach ($this->listenerRegistrars as $registrar) {
                $registrar->register($context);
            }

            // Install single Symfony tick callback that multiplexes to all registered handlers
            $tui->onTick(static fn (TickEvent $event): ?bool => $ticks->dispatch($event));

            // Also register input handlers from the slot registry
            $this->registerSlotInputHandlers($tui, $screen);

            $tui->setFocus($screen->editorWidget());
            $tui->run();

            // ── After event loop exits: check for pending switch ──
            $switchTarget = $this->switchService->consumePendingSwitch();
            if (null !== $switchTarget) {
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
                break; // Normal exit — no pending switch
            }
        }

        return Command::SUCCESS;
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
                $state->transcript[] = $this->blockFactory->error(
                    runId: $state->sessionId,
                    text: 'Runtime error: '.$e->getMessage(),
                    seq: \count($state->transcript) + 1,
                );
            }
            $screen->setTranscriptBlocks($state->transcript);
        } elseif ($state->resuming) {
            $meta = $this->sessionStore->loadMetadata($state->sessionId);
            $existingRunId = $meta['run_id'] ?? null;
            if (\is_string($existingRunId) && '' !== $existingRunId) {
                try {
                    $state->handle = $client->resume($existingRunId);
                    $state->transcript[] = $this->blockFactory->system(
                        runId: $state->sessionId,
                        text: \sprintf('Resumed run %s', $existingRunId),
                        seq: \count($state->transcript) + 1,
                        style: 'muted',
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to resume run', [
                        'exception' => $e,
                        'run_id' => $existingRunId,
                    ]);
                    $state->transcript[] = $this->blockFactory->system(
                        runId: $state->sessionId,
                        text: 'Could not resume run — starting fresh.',
                        seq: \count($state->transcript) + 1,
                        style: 'warning',
                    );
                }
                $screen->setTranscriptBlocks($state->transcript);
            }
        }
    }

    /**
     * Register input handlers from the slot registry as a TUI InputEvent listener.
     *
     * The handler list is read at event time, so late registrations from extensions work.
     */
    private function registerSlotInputHandlers(Tui $tui, ChatScreen $screen): void
    {
        $registry = $screen->registry();

        $tui->addListener(static function (\Symfony\Component\Tui\Event\InputEvent $event) use ($registry): void {
            $handlers = $registry->getInputHandlers();
            if ([] === $handlers) {
                return;
            }

            $data = $event->getData();
            foreach ($handlers as $handler) {
                $handler($data);
            }
        }, priority: 50);
    }
}
