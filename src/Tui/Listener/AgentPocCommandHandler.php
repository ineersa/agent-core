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
use Symfony\Component\Tui\Tui;
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
 *   /agent-poc            Create POC data + open/refresh overlay
 *   /agent-poc close      Remove the overlay
 *   /agent-poc tick       Append a synthetic child event + refresh overlay
 *
 * @internal
 */
final class AgentPocCommandHandler implements SlashCommandHandler
{
    private ?ContainerWidget $overlayContainer = null;

    /** @var list<array<string, mixed>> Cached synthetic child events for display */
    private array $childEvents = [];

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly Tui $tui,
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

        return new TranscriptMessage(
            'POC: Agent control overlay closed.',
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

        // ── Build visible overlay ──
        $this->buildOverlay($sessionId, $parentDir, $agentsDir, $childDir, $childId, $appendTick);

        return new TranscriptMessage(
            'POC: Agent control overlay open. Use /agent-poc tick to simulate live update, /agent-poc close to dismiss.',
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
        string $parentDir,
        string $agentsDir,
        string $childDir,
        string $childId,
        bool $appendTick,
    ): void {
        // Build transcript text from child events
        $transcriptLines = [];
        foreach ($this->childEvents as $event) {
            $type = $event['type'] ?? 'unknown';
            $payload = $event['payload'] ?? [];
            $text = $payload['text'] ?? ($payload['output'] ?? '');
            $toolName = $payload['tool_name'] ?? '';

            $prefix = match (true) {
                'assistant.message' === $type => '  🤖 ',
                'tool_execution.started' === $type => '  🔧 START: '.$toolName,
                'tool_execution.completed' === $type => '  ✅ DONE:  '.$toolName,
                'run.started' === $type => '  ▶  ',
                default => '  ·  ',
            };

            if ('' !== $text) {
                $transcriptLines[] = $prefix.': '.$text;
            } elseif ('' !== $toolName) {
                $transcriptLines[] = $prefix;
            } else {
                $transcriptLines[] = $prefix.$type;
            }
        }

        $eventCount = \count($this->childEvents);

        // Remove existing overlay if present
        if (null !== $this->overlayContainer) {
            $this->screen->removeOverlay($this->overlayContainer);
        }

        // Build new overlay container
        $this->overlayContainer = new ContainerWidget();

        // Build the display text
        $tickNote = $appendTick ? ' (live update appended)' : '';

        $displayText = implode("\n", [
            '┌── AGENT CONTROL POC ───────────────────────────┐',
            '│ Parent session: '.$sessionId,
            '│ Parent dir:     '.$parentDir,
            '│ Registry:       '.$agentsDir.'/registry.json',
            '│ Child dir:      '.$childDir,
            '│',
            '│ Child:  '.$childId.'  (status: running)',
            '│ Events: '.$eventCount.'  (source of truth)',
            '│',
            '│ Child transcript (from nested events.jsonl):',
            '│',
            ...array_map(static fn (string $l) => '│ '.$l, $transcriptLines),
            '│',
            '│ /agent-poc tick  → simulate live update',
            '│ /agent-poc close → dismiss overlay',
            '└────────────────────────────────────────────────┘'.$tickNote,
        ]);

        $overlayWidget = new LiveTextWidget(
            static fn () => $displayText,
            truncate: false,
        );

        $this->overlayContainer->add($overlayWidget);

        $this->screen->insertOverlayBeforeEditor($this->overlayContainer);
        $this->screen->setFocus($overlayWidget);
        $this->screen->requestRender();
    }
}
