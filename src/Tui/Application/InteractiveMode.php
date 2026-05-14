<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Theme\ThemeLoader;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\QuitEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\TextWidget;

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
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final class InteractiveMode
{
    /** @var int Polling interval in seconds (50ms) */
    private const float POLL_INTERVAL = 0.05;
    /** @var array<string, TuiTheme> Cache of resolved themes */
    private array $themeCache = [];

    public function __construct(
        private readonly AppConfigResolver $configResolver,
        private readonly HatfieldSessionStore $sessionStore,
    ) {
    }

    /**
     * Run the interactive TUI for a given session client.
     *
     * @param AgentSessionClient   $client     The runtime session client
     * @param OutputInterface      $output     Unused in interactive mode (kept for API compat)
     * @param StartRunRequest|null $request    Optional pre-configured start request
     * @param TuiTheme|null        $theme      Pre-resolved theme (for testing)
     * @param string               $sessionId  Existing session ID to resume; empty = new session
     * @param string               $projectCwd Project working directory
     *
     * @return int Console exit code
     */
    public function run(
        AgentSessionClient $client,
        OutputInterface $output,
        ?StartRunRequest $request = null,
        ?TuiTheme $theme = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): int {
        // Resolve project cwd
        $cwd = '' !== $projectCwd ? $projectCwd : (getcwd() ?: $this->sessionStore->getProjectDir());

        // Resolve theme from Hatfield config
        $appConfig = $this->configResolver->resolve($cwd);

        $theme ??= $this->createTheme(
            name: $appConfig->tui->theme,
            paths: $appConfig->tui->themePaths,
        );

        // ── Session persistence ──
        $resuming = '' !== $sessionId && $this->sessionStore->exists($cwd, $sessionId);
        if (!$resuming) {
            // Pre-generate session/run ID so session_id === run_id
            $sessionId = $this->sessionStore->generateId();
            $promptText = $request?->prompt ?? '';
            $this->sessionStore->createSession($cwd, $promptText, $sessionId);
        }

        // ── Build the TUI ──
        $tui = new Tui();

        // ── Header: Hatfield ASCII logo ──
        $headerWidget = new TextWidget($this->buildHatfieldLogo($theme));
        $tui->add($headerWidget);

        // ── Separator ──
        $sepWidget = new TextWidget($theme->color(ThemeColor::Separator, str_repeat('─', 120)));
        $tui->add($sepWidget);

        // ── Transcript / history ──
        $transcript = [];
        if ($resuming) {
            $transcript = $this->loadTranscriptLines($cwd, $sessionId, $theme);
            if ([] === $transcript) {
                $transcript = ['  Session '.$sessionId.' — no messages yet.'];
            }
        } else {
            $transcript = ['  Welcome to Hatfield. Type a message below to start.'];
            // Persist initial system entry
            $this->sessionStore->appendTranscriptEntry($cwd, $sessionId, new TranscriptEntry(
                role: 'system',
                text: 'Session started',
                meta: ['session_id' => $sessionId],
            ));
        }
        $transcriptWidget = new TextWidget($theme->text(implode("\n", $transcript)));
        $tui->add($transcriptWidget);

        // ── Working status ──
        $workingWidget = new TextWidget($theme->muted('  ● idle'));
        $tui->add($workingWidget);

        // ── Status panel ──
        $statusWidget = new TextWidget('');
        $tui->add($statusWidget);

        // ── Editor separator ──
        $editorSepWidget = new TextWidget($theme->color(ThemeColor::Separator, str_repeat('─', 120)));
        $tui->add($editorSepWidget);

        // ── Editor ──
        $editorWidget = new EditorWidget();
        $editorWidget->setMinVisibleLines(1);
        $editorWidget->setMaxVisibleLines(10);
        $tui->add($editorWidget);

        // ── Footer separator ──
        $footerSepWidget = new TextWidget($theme->color(ThemeColor::Separator, str_repeat('─', 120)));
        $tui->add($footerSepWidget);

        // ── Footer ──
        $footerText = \sprintf(
            '  %s%s %s%s  %sCtrl+D quit  Ctrl+C cancel',
            $theme->color(ThemeColor::Footer, '◆ hatfield'),
            $theme->muted('│'),
            $theme->muted('session '),
            $theme->accent($sessionId),
            $theme->muted(''),
        );
        $footerWidget = new TextWidget($footerText, truncate: true);
        $tui->add($footerWidget);

        // ── Run state ──
        $handle = null;
        if (null !== $request && '' !== $request->prompt) {
            // Inject session ID as the run ID
            if ('' === $request->runId) {
                $request = new StartRunRequest(
                    prompt: $request->prompt,
                    runId: $sessionId,
                    cwd: $request->cwd,
                    options: $request->options,
                );
            }
            $handle = $client->start($request);
            $transcript[] = $theme->accent(\sprintf('  Run started: %s', $request->prompt));
            $this->sessionStore->appendTranscriptEntry($cwd, $sessionId, new TranscriptEntry(
                role: 'system',
                text: \sprintf('Run started: %s', $request->prompt),
                meta: ['run_id' => $handle->runId],
            ));
            $this->sessionStore->updateMetadata($cwd, $sessionId, [
                'run_id' => $handle->runId,
                'prompt' => $request->prompt,
            ]);
            $transcriptWidget->setText(implode("\n", array_map(
                static fn (string $line) => $theme->text($line),
                $transcript,
            )));
        } elseif ($resuming) {
            // Reconnect to already-started run if metadata recorded one
            $meta = $this->sessionStore->loadMetadata($cwd, $sessionId);
            $existingRunId = $meta['run_id'] ?? null;
            if (\is_string($existingRunId) && '' !== $existingRunId) {
                try {
                    $handle = $client->resume($existingRunId);
                    $transcript[] = $theme->muted(\sprintf('  Resumed run %s', $existingRunId));
                    $transcriptWidget->setText(implode("\n", array_map(
                        static fn (string $line) => $theme->text($line),
                        $transcript,
                    )));
                } catch (\Throwable) {
                    // Run may no longer exist — continue without reconnecting
                    $transcript[] = $theme->warning('  Could not resume run — starting fresh.');
                    $transcriptWidget->setText(implode("\n", array_map(
                        static fn (string $line) => $theme->text($line),
                        $transcript,
                    )));
                }
            }
        }

        // ── Runtime event polling state ──
        $lastSeq = 0;
        $lastPoll = 0.0;
        $eventsIter = null;

        // Capture class properties as locals for closure use
        $sessionStore = $this->sessionStore;

        // ── Listeners ──

        // Submit: user pressed Enter in the editor
        $tui->addListener(static function (SubmitEvent $event) use (
            $client, $theme, $transcriptWidget, &$transcript, &$handle, &$request,
            $sessionStore, $sessionId, $cwd, $workingWidget, &$lastSeq, &$eventsIter,
        ) {
            $text = $event->getText();
            if ('' === $text) {
                return;
            }

            // Append user message to transcript
            $transcript[] = $theme->color(ThemeColor::UserMessage, '  ❯ '.str_replace("\n", "\n    ", $text));
            $sessionStore->appendTranscriptEntry($cwd, $sessionId, new TranscriptEntry(
                role: 'user',
                text: $text,
                meta: ['session_id' => $sessionId],
            ));

            // Clear editor
            $event->getTarget()?->setText('');

            // Start a run if this is the first message
            if (null === $handle && null === $request) {
                $request = new StartRunRequest(prompt: $text, runId: $sessionId);
                $handle = $client->start($request);
                $transcript[] = $theme->accent(\sprintf('  Run started: %s', $text));
                $sessionStore->updateMetadata($cwd, $sessionId, [
                    'run_id' => $sessionId,
                    'prompt' => $text,
                ]);
                $lastSeq = 0;
                $eventsIter = null;
            } elseif (null !== $handle) {
                $client->send($handle->runId, new UserCommand(type: 'message', text: $text));
            }

            // Show processing indicator
            $transcript[] = $theme->muted('  ◇ Processing...');
            $workingWidget->setText($theme->color(ThemeColor::Working, '  ◐ Working...'));

            $transcriptWidget->setText(implode("\n", $transcript));
        });

        // Cancel: Escape in editor
        $tui->addListener(static function (CancelEvent $event) {
            $event->getTarget()?->setText('');
        });

        // Quit: stop the TUI loop
        $tui->addListener(static function (QuitEvent $event) use ($tui) {
            $tui->stop();
        });

        // Input interception: Ctrl+D to quit, Ctrl+C double-press to quit
        $ctrlCLast = 0.0;

        $tui->addListener(static function (InputEvent $event) use (
            $tui, $editorWidget, $statusWidget, $theme, &$ctrlCLast,
        ) {
            $data = $event->getData();

            if ("\x04" === $data) {
                $event->stopPropagation();
                $tui->stop();

                return;
            }

            if ("\x03" === $data) {
                $event->stopPropagation();

                $now = microtime(true);
                if ($ctrlCLast > 0.0 && ($now - $ctrlCLast) < 1.5) {
                    $tui->stop();

                    return;
                }

                if ('' !== $editorWidget->getText()) {
                    $editorWidget->setText('');
                    $statusWidget->setText('');
                } else {
                    $statusWidget->setText(
                        $theme->warning('  Press Ctrl+C again to exit'),
                    );
                }

                $ctrlCLast = $now;

                return;
            }

            if ($ctrlCLast > 0.0) {
                $ctrlCLast = 0.0;
                $statusWidget->setText('');
            }
        }, priority: 100);

        // Tick callback: poll AgentSessionClient::events() for new runtime events
        $tui->onTick(static function (TickEvent $event) use (
            $client, $theme, &$handle, &$lastSeq, &$lastPoll, &$eventsIter,
            $transcriptWidget, &$transcript, $workingWidget,
            $sessionStore, $sessionId, $cwd,
        ): ?bool {
            if (null === $handle) {
                return null;
            }

            $now = microtime(true);
            // Throttle polling
            if (($now - $lastPoll) < self::POLL_INTERVAL) {
                return null;
            }
            $lastPoll = $now;

            try {
                if (null === $eventsIter) {
                    $eventsIter = $client->events($handle->runId);
                    if ($eventsIter instanceof \Traversable) {
                        $eventsIter = iterator_to_array($eventsIter);
                    }
                }

                if (!\is_array($eventsIter)) {
                    return null;
                }

                $hasNew = false;
                $processingRemoved = false;

                foreach ($eventsIter as $runtimeEvent) {
                    $seq = $runtimeEvent->seq;
                    if ($seq <= $lastSeq) {
                        continue;
                    }
                    $lastSeq = $seq;
                    $hasNew = true;

                    // Persist the runtime event
                    $sessionStore->appendRuntimeEvent($cwd, $sessionId, $runtimeEvent->toArray());

                    // Remove "Processing..." placeholder on first real event
                    if (!$processingRemoved) {
                        $lastIdx = \count($transcript) - 1;
                        if ($lastIdx >= 0 && str_contains($transcript[$lastIdx], 'Processing...')) {
                            array_pop($transcript);
                        }
                        $processingRemoved = true;
                    }

                    // Map event to transcript entry
                    $entry = self::formatEventForTranscript($runtimeEvent, $theme);
                    if (null !== $entry) {
                        $transcript[] = $entry;
                        $sessionStore->appendTranscriptEntry($cwd, $sessionId, new TranscriptEntry(
                            role: 'assistant',
                            text: $entry,
                            meta: [
                                'run_id' => $runtimeEvent->runId,
                                'seq' => $seq,
                                'event_type' => $runtimeEvent->type,
                            ],
                        ));
                    }
                }

                if ($hasNew) {
                    $transcriptWidget->setText(implode("\n", $transcript));
                    $workingWidget->setText($theme->muted('  ● idle'));
                }

                // Refresh events iter periodically
                if ($hasNew && \is_array($eventsIter) && \count($eventsIter) > 0) {
                    $eventsIter = null; // Force re-fetch next tick
                }
            } catch (\Throwable) {
                // Silently skip polling errors; show nothing to user
            }

            return null;
        });

        // Focus the editor so user can type immediately
        $tui->setFocus($editorWidget);

        // ── Run the event loop (blocks until stop() is called) ──
        $tui->run();

        return Command::SUCCESS;
    }

    /**
     * Create the active theme from Hatfield config.
     *
     * @param string       $name  Selected theme name from config
     * @param list<string> $paths Theme search directories (already resolved)
     */
    public function createTheme(string $name, array $paths): TuiTheme
    {
        $loader = new ThemeLoader();

        $allPalettes = [];
        foreach ($paths as $path) {
            $palettes = $loader->loadDirectory($path);
            foreach ($palettes as $palette) {
                if (!isset($allPalettes[$palette->name])) {
                    $allPalettes[$palette->name] = $palette;
                }
            }
        }

        $builtinPath = \dirname(__DIR__, 3).'/config/themes';
        $builtins = $loader->loadDirectory($builtinPath);
        foreach ($builtins as $palette) {
            if (!isset($allPalettes[$palette->name])) {
                $allPalettes[$palette->name] = $palette;
            }
        }

        $registry = new ThemeRegistry(
            builtin: array_values($allPalettes),
            defaultName: 'cyberpunk',
        );

        return new DefaultTheme($registry->getOrDefault($name));
    }

    /**
     * Convert a RuntimeEvent into a displayable transcript line.
     *
     * Returns null if the event type should not appear in the transcript.
     */
    private static function formatEventForTranscript(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent $event, TuiTheme $theme): ?string
    {
        $payload = $event->payload;

        return match ($event->type) {
            'run_started' => $theme->accent(\sprintf('  Run started: %s', $payload['prompt'] ?? '')),
            'message_update' => $theme->color(ThemeColor::AssistantMessage, '  ◇ '.mb_substr((string) ($payload['content'] ?? ($payload['text'] ?? '')), 0, 500)),
            'message_end' => $theme->muted('  ◇ (end of message)'),
            'tool_execution_start' => \sprintf(
                '  %s %s %s',
                $theme->color(ThemeColor::Tool, '●'),
                $theme->accent((string) ($payload['tool'] ?? 'tool')),
                $theme->muted((string) ($payload['input'] ?? '')),
            ),
            'tool_execution_end' => \sprintf(
                '  %s %s %s',
                $theme->color(ThemeColor::Tool, '●'),
                $theme->accent((string) ($payload['tool'] ?? 'tool')),
                $theme->muted((string) ($payload['summary'] ?? 'done')),
            ),
            'turn_start', 'turn_end', 'agent_start', 'agent_end' => null, // Structural events — skip
            default => $theme->muted(\sprintf('  · %s', $event->type)),
        };
    }

    /**
     * Load persisted transcript entries and format them for display.
     *
     * @return list<string>
     */
    private function loadTranscriptLines(string $projectCwd, string $sessionId, TuiTheme $theme): array
    {
        $entries = $this->sessionStore->getTranscript($projectCwd, $sessionId);
        if ([] === $entries) {
            return [];
        }

        $lines = [];
        foreach ($entries as $entry) {
            $prefix = match ($entry->role) {
                'user' => $theme->color(ThemeColor::UserMessage, '  ❯ '),
                'assistant' => $theme->color(ThemeColor::AssistantMessage, '  ◇ '),
                'tool' => $theme->color(ThemeColor::Tool, '  ● '),
                'error' => $theme->error('  ✗ '),
                default => $theme->muted('  · '),
            };
            $lines[] = $prefix.str_replace("\n", "\n    ", $entry->text);
        }

        return $lines;
    }

    private function buildHatfieldLogo(TuiTheme $theme): string
    {
        $logo = <<<'ASCII'
██╗ ██╗      ██╗  ██╗ █████╗ ████████╗███████╗██╗███████╗██╗     ██████╗     ██╗██╗██╗
╚██╗╚██╗     ██║  ██║██╔══██╗╚══██╔══╝██╔════╝██║██╔════╝██║     ██╔══██╗    ██║██║██║
 ╚██╗╚██╗    ███████║███████║   ██║   █████╗  ██║█████╗  ██║     ██║  ██║    ██║██║██║
 ██╔╝██╔╝    ██╔══██║██╔══██║   ██║   ██╔══╝  ██║██╔══╝  ██║     ██║  ██║    ╚═╝╚═╝╚═╝
██╔╝██╔╝     ██║  ██║██║  ██║   ██║   ██║     ██║███████╗███████╗██████╔╝    ██╗██╗██╗
╚═╝ ╚═╝      ╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝     ╚═╝╚══════╝╚══════╝╚═════╝     ╚═╝╚═╝╚═╝
ASCII;

        return $theme->color(ThemeColor::Header, $logo);
    }
}
