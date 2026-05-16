<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\TranscriptEntry;
use Symfony\Component\Console\Command\Command;
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
    ) {
    }

    /**
     * Run the interactive TUI for a given session client.
     *
     * @param AgentSessionClient   $client     The runtime session client
     * @param StartRunRequest|null $request    Optional pre-configured start request (from AgentCommand)
     * @param TuiTheme|null        $theme      Pre-resolved theme (for testing)
     * @param string               $sessionId  Existing session ID to resume; empty = new session
     * @param string               $projectCwd Project working directory
     *
     * @return int Console exit code
     */
    public function run(
        AgentSessionClient $client,
        ?StartRunRequest $request = null,
        ?TuiTheme $theme = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): int {
        $cwd = '' !== $projectCwd ? $projectCwd : (getcwd() ?: $this->sessionStore->getProjectDir());
        $theme = $this->themeFactory->create($cwd, $theme);

        // ── Configure storage to use active project cwd ──
        $sessionsBasePath = $this->sessionStore->resolveSessionsBasePath($cwd);
        $client->initializeSessionsBasePath($sessionsBasePath);

        // ── Initialize session ──
        $state = $this->sessionInit->initialize($cwd, $sessionId, $request);
        $state->transcript = $this->sessionInit->buildInitialTranscript($state);

        // ── Build screen and mount widget tree ──
        $tui = new Tui();
        $screen = new ChatScreen($theme, $state->sessionId);
        $screen->mount($tui);

        // Set initial transcript
        $screen->setTranscriptEntries($state->transcript);

        // ── Start or resume the run ──
        $this->startOrResumeRun($client, $state, $screen);

        // ── Register listeners (DI-driven, stateless registrars) ──
        $context = new TuiRuntimeContext(
            tui: $tui,
            client: $client,
            state: $state,
            screen: $screen,
            sessionStore: $this->sessionStore,
        );

        foreach ($this->listenerRegistrars as $registrar) {
            $registrar->register($context);
        }

        // Also register input handlers from the slot registry
        $this->registerSlotInputHandlers($tui, $screen);

        $tui->setFocus($screen->editorWidget());
        $tui->run();

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
        if (null !== $state->request && '' !== $state->request->prompt) {
            $state->handle = $client->start($state->request);
            $state->transcript[] = new TranscriptEntry(
                text: \sprintf('Run started: %s', $state->request->prompt),
                role: 'system',
                style: 'accent',
            );
            $this->sessionStore->appendTranscriptEntry(
                $state->cwd,
                $state->sessionId,
                new PersistedTranscriptEntry(
                    role: 'system',
                    text: \sprintf('Run started: %s', $state->request->prompt),
                    meta: ['run_id' => $state->handle->runId],
                ),
            );
            $this->sessionStore->updateMetadata($state->cwd, $state->sessionId, [
                'run_id' => $state->handle->runId,
                'prompt' => $state->request->prompt,
            ]);
            $screen->setTranscriptEntries($state->transcript);
        } elseif ($state->resuming) {
            $meta = $this->sessionStore->loadMetadata($state->cwd, $state->sessionId);
            $existingRunId = $meta['run_id'] ?? null;
            if (\is_string($existingRunId) && '' !== $existingRunId) {
                try {
                    $state->handle = $client->resume($existingRunId);
                    $state->transcript[] = new TranscriptEntry(
                        text: \sprintf('Resumed run %s', $existingRunId),
                        role: 'system',
                        style: 'muted',
                    );
                } catch (\Throwable) {
                    $state->transcript[] = new TranscriptEntry(
                        text: 'Could not resume run — starting fresh.',
                        role: 'system',
                        style: 'warning',
                    );
                }
                $screen->setTranscriptEntries($state->transcript);
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
