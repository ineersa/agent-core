<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Widget\LiveTextWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * POC slash command handler for AGENT-03 hidden run control prototype.
 *
 * Creates synthetic nested child-run data under the active parent session
 * directory at .hatfield/sessions/<parent>/artifacts/agents/ and opens a
 * visible overlay showing the registry, events, and child transcript.
 *
 * THIS IS THROWAWAY POC CODE — not production API.  Delete or rewrite
 * entirely before building the real agent control view.
 *
 * Commands:
 *   /agent-poc            Create POC data + open/refresh overlay + compact status
 *   /agent-poc close      Remove overlay, clear compact status
 *   /agent-poc tick       Append synthetic child event, update overlay + status
 *
 * The overlay uses insertOverlayAfterEditor() (like CompletionMenu) so the
 * editor keeps focus — user can immediately type /agent-poc tick or /agent-poc close.
 *
 * Compact status is set via ChatScreen::setStatus() so the main TUI shows
 * e.g. "Agents: scout-poc running · 4 events · /agent-poc".
 *
 * @internal
 */
final class AgentPocCommandHandler implements SlashCommandHandler
{
    private const string STATUS_KEY = 'Agents';
    private ?ContainerWidget $overlayContainer = null;

    /** @var list<array<string, mixed>> Cached synthetic child events for display */
    private array $childEvents = [];

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $sessionId = $this->state->sessionId;

        if ('' === $sessionId) {
            return new TranscriptMessage(
                'POC: No active session. Start a conversation first.',
                'system',
                'error',
            );
        }

        $subCommand = trim($command->args);

        if ('close' === $subCommand) {
            return $this->handleClose();
        }

        return $this->handleOpenOrRefresh($sessionId, 'tick' === $subCommand);
    }

    private function handleClose(): CommandResult
    {
        if (null !== $this->overlayContainer) {
            $this->screen->removeOverlay($this->overlayContainer);
            $this->screen->requestRender();
            $this->overlayContainer = null;
        }

        // Clear compact agent status from the main TUI status panel.
        $this->screen->setStatus(self::STATUS_KEY, null);

        return new TranscriptMessage(
            'POC: Agent control overlay closed. Agent status cleared.',
            'system',
            'muted',
        );
    }

    private function handleOpenOrRefresh(string $sessionId, bool $appendTick): CommandResult
    {
        $parentDir = $this->sessionStore->resolveSessionsBasePath().'/'.$sessionId;
        $agentsDir = $parentDir.'/artifacts/agents';

        // Ensure directories exist
        if (!is_dir($agentsDir)) {
            mkdir($agentsDir, 0777, true);
        }

        $childId = 'scout-poc';
        $childDir = $agentsDir.'/'.$childId;

        // On first invocation, create initial POC data; on tick, append an event
        if (!is_dir($childDir)) {
            mkdir($childDir, 0777, true);
            $this->childEvents = $this->createInitialEvents($childId);
        } elseif ($appendTick) {
            $this->childEvents = $this->loadAndAppendEvent($childDir, $childId);
        } else {
            $this->childEvents = $this->loadEventsFromFile($childDir);
        }

        // Write registry
        $this->writeRegistry($agentsDir, $childId, $childDir);

        // Write child events
        $this->writeEventFile($childDir.'/events.jsonl', $this->childEvents);

        // Write child state
        $this->writeStateFile($childDir.'/state.json', $childId);

        // ── Set compact agent status in the main TUI status panel ──
        $eventCount = \count($this->childEvents);
        $this->screen->setStatus(
            self::STATUS_KEY,
            \sprintf('scout-poc running · %d events · /agent-poc', $eventCount),
        );

        // ── Build visible overlay (below editor, keeps editor focus) ──
        $this->buildOverlay($sessionId, $agentsDir, $childId, $appendTick);

        return new TranscriptMessage(
            'POC: Agent control overlay open below editor. Use /agent-poc tick or /agent-poc close.',
            'system',
        );
    }

    /** @return list<array<string, mixed>> */
    private function createInitialEvents(string $childId): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            [
                'schema_version' => 1,
                'run_id' => $childId,
                'seq' => 1,
                'turn_no' => 1,
                'type' => 'run.started',
                'payload' => ['agent_name' => 'scout'],
                'ts' => $now->format(\DATE_ATOM),
            ],
            [
                'schema_version' => 1,
                'run_id' => $childId,
                'seq' => 2,
                'turn_no' => 1,
                'type' => 'assistant.message',
                'payload' => [
                    'text' => 'Scout POC child event #1: Exploring codebase structure...',
                    'message_id' => 'poc-msg-1',
                ],
                'ts' => $now->modify('+1 second')->format(\DATE_ATOM),
            ],
            [
                'schema_version' => 1,
                'run_id' => $childId,
                'seq' => 3,
                'turn_no' => 1,
                'type' => 'tool_execution.started',
                'payload' => [
                    'tool_call_id' => 'poc-tc-1',
                    'tool_name' => 'semantic_search',
                    'args' => ['query' => 'find implementation'],
                ],
                'ts' => $now->modify('+2 seconds')->format(\DATE_ATOM),
            ],
            [
                'schema_version' => 1,
                'run_id' => $childId,
                'seq' => 4,
                'turn_no' => 1,
                'type' => 'tool_execution.completed',
                'payload' => [
                    'tool_call_id' => 'poc-tc-1',
                    'tool_name' => 'semantic_search',
                    'output' => 'Found 3 matching files in src/CodingAgent/Agent/',
                ],
                'ts' => $now->modify('+3 seconds')->format(\DATE_ATOM),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function loadAndAppendEvent(string $childDir, string $childId): array
    {
        $existing = $this->loadEventsFromFile($childDir);
        $count = \count($existing);
        $lastSeq = $count > 0 ? $existing[$count - 1]['seq'] : 0;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing[] = [
            'schema_version' => 1,
            'run_id' => $childId,
            'seq' => $lastSeq + 1,
            'turn_no' => 1,
            'type' => 'assistant.message',
            'payload' => [
                'text' => \sprintf('Scout POC live update #%d: New findings discovered...', $count + 1),
                'message_id' => \sprintf('poc-msg-%d', $count + 1),
            ],
            'ts' => $now->format(\DATE_ATOM),
        ];

        return $existing;
    }

    /** @return list<array<string, mixed>> */
    private function loadEventsFromFile(string $childDir): array
    {
        $eventsPath = $childDir.'/events.jsonl';

        if (!file_exists($eventsPath)) {
            return [];
        }

        $events = [];
        $lines = file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    private function writeRegistry(string $agentsDir, string $childId, string $childDir): void
    {
        $registry = [
            'schema' => 1,
            'entries' => [
                [
                    'child_run_id' => $childId,
                    'parent_run_id' => $this->state->sessionId,
                    'artifact_id' => $childId,
                    'agent_name' => 'scout',
                    'definition_source' => 'POC hardcoded (not from .agents/)',
                    'status' => 'running',
                    'launch_mode' => 'background',
                    'depth' => 1,
                    'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                    'completed_at' => null,
                    'attention_state' => null,
                    'events_path' => 'artifacts/agents/'.$childId.'/events.jsonl',
                    'artifact_path' => null,
                ],
            ],
        ];

        file_put_contents(
            $agentsDir.'/registry.json',
            json_encode($registry, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            \LOCK_EX,
        );
    }

    /** @param list<array<string, mixed>> $events */
    private function writeEventFile(string $path, array $events): void
    {
        $lines = [];
        foreach ($events as $event) {
            $lines[] = json_encode($event, \JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($path, implode("\n", $lines)."\n", \LOCK_EX);
    }

    private function writeStateFile(string $path, string $childId): void
    {
        $state = [
            'run_id' => $childId,
            'parent_run_id' => $this->state->sessionId,
            'status' => 'running',
            'agent_name' => 'scout',
            'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ];

        file_put_contents($path, json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }

    private function buildOverlay(
        string $sessionId,
        string $agentsDir,
        string $childId,
        bool $appendTick,
    ): void {
        $eventCount = \count($this->childEvents);

        // Extract latest assistant message text for the selected-child detail
        $latestText = '';
        for ($i = $eventCount - 1; $i >= 0; --$i) {
            $payload = $this->childEvents[$i]['payload'] ?? [];
            $candidate = $payload['text'] ?? '';
            if ('' !== $candidate) {
                $latestText = $candidate;
                break;
            }
        }

        // Remove existing overlay if present
        if (null !== $this->overlayContainer) {
            $this->screen->removeOverlay($this->overlayContainer);
        }

        // Build new overlay container
        $this->overlayContainer = new ContainerWidget();

        $tickNote = $appendTick ? ' (live update appended)' : '';
        $parentSessionIdShort = mb_strlen($sessionId) > 20
            ? mb_substr($sessionId, 0, 17).'...'
            : $sessionId;

        // Compact overlay: list + selected child detail + controls
        $displayText = implode("\n", [
            '┌── AGENTS — session '.$parentSessionIdShort.' ────────────────────────┐',
            '│',
            '│  '.$childId.'    running    '.$eventCount.' events    background',
            '│  Registry: '.$agentsDir.'/registry.json',
            '│  Events:   artifacts/agents/'.$childId.'/events.jsonl',
            '│',
            ('' !== $latestText)
                ? '│  Latest: '.$latestText
                : '│  (no assistant messages yet)',
            '│',
            '│  tick │ close'.(true === $appendTick ? '  ← just updated' : ''),
            '└────────────────────────────────────────────────────────┘'.$tickNote,
        ]);

        $overlayWidget = new LiveTextWidget(
            static fn () => $displayText,
            truncate: false,
        );

        $this->overlayContainer->add($overlayWidget);

        // Use insertOverlayAfterEditor like CompletionMenu so the editor
        // keeps focus — user can immediately type /agent-poc tick or close.
        // Do NOT call setFocus() on the overlay.
        $this->screen->insertOverlayAfterEditor($this->overlayContainer);
        $this->screen->requestRender();
    }
}
