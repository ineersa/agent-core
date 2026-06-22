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
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * POC slash command handler for AGENT-03 hidden run control prototype.
 *
 * Creates synthetic nested child-run data under the active parent session
 * directory at .hatfield/sessions/<parent>/artifacts/agents/ and opens a
 * modal control overlay (above-editor, focused SelectListWidget).
 *
 * THIS IS THROWAWAY POC CODE — not production API.  Delete or rewrite
 * entirely before building the real agent control view.
 *
 * Commands:
 *   /agent-poc            Create POC data + open modal control overlay
 *   /agent-poc close      Remove overlay (fallback; also closeable via Esc / select)
 *
 * Overlay controls (navigate with arrow keys, select with Enter, cancel with Esc):
 *   tick / update         Append synthetic child event, refresh overlay
 *   steer                 Append synthetic steering event
 *   cancel child          Mark child cancelled in registry/state
 *   retrieve artifact     Write a synthetic artifact, show path
 *   close overlay         Dismiss the control plane
 *
 * Compact status is set via ChatScreen::setStatus() so the main TUI shows
 * e.g. "Agents: scout-poc running · 4 events · open /agent-poc".
 * Compact status persists when the overlay is closed — it only changes
 * on state updates (e.g. child cancelled).
 *
 * The overlay uses insertOverlayBeforeEditor() (like QuestionController)
 * so it renders as a modal above the editor with focus — the user interacts
 * via arrow keys + Enter/Esc, not via slash commands while open.
 *
 * @internal
 */
final class AgentPocCommandHandler implements SlashCommandHandler
{
    private const string STATUS_KEY = 'Agents';

    private ?ContainerWidget $overlayContainer = null;
    private ?SelectListWidget $overlayListWidget = null;
    private bool $overlayOpen = false;

    /** @var list<array<string, mixed>> Cached synthetic child events for display */
    private array $childEvents = [];

    private string $sessionId = '';
    private string $agentsDir = '';
    private string $childId = 'scout-poc';
    private string $childDir = '';
    private string $childStatus = 'running';

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $this->sessionId = $this->state->sessionId;

        if ('' === $this->sessionId) {
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

        return $this->handleOpen();
    }

    private function handleClose(): CommandResult
    {
        $this->closeOverlay();

        return new TranscriptMessage(
            'POC: Agent control overlay closed. Compact status remains (if agents active).',
            'system',
            'muted',
        );
    }

    private function handleOpen(): CommandResult
    {
        $parentDir = $this->sessionStore->resolveSessionsBasePath().'/'.$this->sessionId;
        $this->agentsDir = $parentDir.'/artifacts/agents';
        $this->childDir = $this->agentsDir.'/'.$this->childId;

        // Ensure directories exist
        if (!is_dir($this->agentsDir)) {
            mkdir($this->agentsDir, 0777, true);
        }

        // On first invocation, create initial POC data
        if (!is_dir($this->childDir)) {
            mkdir($this->childDir, 0777, true);
            $this->childEvents = $this->createInitialEvents($this->childId);
            $this->childStatus = 'running';
        } else {
            $this->childEvents = $this->loadEventsFromFile($this->childDir);
            $this->readStatusFromState();
        }

        // Persist files
        $this->writeRegistry($this->agentsDir, $this->childId, $this->childDir);
        $this->writeEventFile($this->childDir.'/events.jsonl', $this->childEvents);
        $this->writeStateFile($this->childDir.'/state.json', $this->childId);

        // ── Set compact agent status in the main TUI status panel ──
        $this->updateCompactStatus();

        // ── Build modal control overlay (above editor, focused) ──
        $this->buildOverlay();

        return new TranscriptMessage(
            'POC: Agent control overlay open. Use ↑↓/Enter/Esc to control.',
            'system',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Overlay lifecycle
    // ═══════════════════════════════════════════════════════════════

    private function closeOverlay(): void
    {
        if (null !== $this->overlayContainer) {
            $this->screen->removeOverlay($this->overlayContainer);
            $this->screen->requestRender();
        }
        $this->overlayContainer = null;
        $this->overlayListWidget = null;
        $this->overlayOpen = false;

        // Compact status intentionally NOT cleared — it stays visible
        // until the user explicitly dismisses it or the child state changes.
    }

    private function buildOverlay(): void
    {
        // Remove existing overlay if present
        if (null !== $this->overlayContainer) {
            $this->screen->removeOverlay($this->overlayContainer);
        }

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

        $parentSessionIdShort = mb_strlen($this->sessionId) > 24
            ? mb_substr($this->sessionId, 0, 21).'...'
            : $this->sessionId;

        // ── Build container ──
        $this->overlayContainer = new ContainerWidget();

        // Header
        $this->overlayContainer->add(
            new TextWidget(
                text: \sprintf('┌─ AGENTS — session %s ─┐', $parentSessionIdShort),
                truncate: true,
            ),
        );

        // Agent list row (single child in POC)
        $statusIcon = match ($this->childStatus) {
            'running' => '▶',
            'cancelled' => '✕',
            default => '?',
        };
        $this->overlayContainer->add(
            new TextWidget(
                text: \sprintf(
                    '│  %s %s  %s  %d events  background',
                    $statusIcon,
                    $this->childId,
                    $this->childStatus,
                    $eventCount,
                ),
                truncate: true,
            ),
        );

        // Separator / selected child header
        $this->overlayContainer->add(
            new TextWidget(
                text: \sprintf('├ Selected: %s ────────────────────────', $this->childId),
                truncate: true,
            ),
        );

        // Latest event detail
        if ('' !== $latestText) {
            $this->overlayContainer->add(
                new TextWidget(
                    text: '│  Latest: '.$latestText,
                    truncate: true,
                ),
            );
        } else {
            $this->overlayContainer->add(
                new TextWidget(text: '│  (no assistant messages yet)', truncate: true),
            );
        }

        // Path refs
        $this->overlayContainer->add(
            new TextWidget(
                text: '│  Events:  artifacts/agents/'.$this->childId.'/events.jsonl',
                truncate: true,
            ),
        );

        // Controls section
        $this->overlayContainer->add(
            new TextWidget(text: '│', truncate: true),
        );
        $this->overlayContainer->add(
            new TextWidget(text: '│  Controls ↑↓/Enter │ Esc to close', truncate: true),
        );

        // ── SelectListWidget for control actions ──
        $controls = $this->buildControlItems($eventCount);

        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

        $this->overlayListWidget = new SelectListWidget(
            items: $controls,
            maxVisible: 7,
            keybindings: $kb,
        );

        $this->overlayListWidget->onSelect(function (SelectEvent $event): void {
            $value = $event->getItem()['value'];
            $this->handleControlAction($value);
        });

        $this->overlayListWidget->onCancel(function (CancelEvent $event): void {
            $this->closeOverlay();
        });

        $this->overlayContainer->add($this->overlayListWidget);

        // ── Mount as modal (above editor, steals focus) ──
        $this->screen->insertOverlayBeforeEditor($this->overlayContainer);
        $this->screen->setFocus($this->overlayListWidget);
        $this->screen->requestRender();
        $this->overlayOpen = true;
    }

    /**
     * @return array<array{value: string, label: string, description?: string}>
     */
    private function buildControlItems(int $eventCount): array
    {
        return [
            [
                'value' => 'tick',
                'label' => \sprintf('tick / update      (%d events)', $eventCount),
                'description' => 'Append a synthetic live child event',
            ],
            [
                'value' => 'steer',
                'label' => 'steer',
                'description' => 'Append a synthetic steering note (POC)',
            ],
            [
                'value' => 'cancel_child',
                'label' => 'cancel child',
                'description' => 'Mark child as cancelled in registry/state',
            ],
            [
                'value' => 'retrieve',
                'label' => 'retrieve artifact',
                'description' => 'Write a synthetic artifact, show path',
            ],
            [
                'value' => 'close',
                'label' => 'close overlay',
                'description' => 'Dismiss the control panel (compact status stays)',
            ],
        ];
    }

    private function handleControlAction(string $action): void
    {
        // Close the overlay first (safe — QuestionController does this too)
        $this->closeOverlay();

        match ($action) {
            'tick' => $this->performTick(),
            'steer' => $this->performSteer(),
            'cancel_child' => $this->performCancel(),
            'retrieve' => $this->performRetrieve(),
            'close' => null, // Already closed — compact status stays
            default => null,
        };

        // Rebuild overlay for non-close actions (close already handled above)
        if ('close' !== $action) {
            // Persist updated state
            $this->writeRegistry($this->agentsDir, $this->childId, $this->childDir);
            $this->writeEventFile($this->childDir.'/events.jsonl', $this->childEvents);
            $this->writeStateFile($this->childDir.'/state.json', $this->childId);

            // Update compact status
            $this->updateCompactStatus();

            // Re-open the modal with refreshed data
            $this->buildOverlay();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Control actions
    // ═══════════════════════════════════════════════════════════════

    private function performTick(): void
    {
        $this->childEvents = $this->loadAndAppendEvent($this->childDir, $this->childId);
    }

    private function performSteer(): void
    {
        $existing = $this->loadEventsFromFile($this->childDir);
        $count = \count($existing);
        $lastSeq = $count > 0 ? $existing[$count - 1]['seq'] : 0;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing[] = [
            'schema_version' => 1,
            'run_id' => $this->childId,
            'seq' => $lastSeq + 1,
            'turn_no' => 1,
            'type' => 'agent_control.steer',
            'payload' => [
                'note' => \sprintf('Steering note accepted (POC) #%d', $count + 1),
                'message_id' => \sprintf('poc-steer-%d', $count + 1),
            ],
            'ts' => $now->format(\DATE_ATOM),
        ];
        $this->childEvents = $existing;
    }

    private function performCancel(): void
    {
        $this->childStatus = 'cancelled';

        // Add a synthetic cancel event
        $existing = $this->loadEventsFromFile($this->childDir);
        $count = \count($existing);
        $lastSeq = $count > 0 ? $existing[$count - 1]['seq'] : 0;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing[] = [
            'schema_version' => 1,
            'run_id' => $this->childId,
            'seq' => $lastSeq + 1,
            'turn_no' => 1,
            'type' => 'agent_control.cancelled',
            'payload' => [
                'reason' => 'User cancelled via POC control overlay',
                'message_id' => \sprintf('poc-cancel-%d', $count + 1),
            ],
            'ts' => $now->format(\DATE_ATOM),
        ];
        $this->childEvents = $existing;
    }

    private function performRetrieve(): void
    {
        // Write a synthetic artifact file
        $artifactPath = $this->childDir.'/artifact-poc.json';
        $artifact = [
            'artifact_id' => 'poc-artifact-1',
            'agent_name' => 'scout',
            'type' => 'findings',
            'content' => [
                'summary' => 'POC findings: nested child storage works.',
                'files_found' => 3,
                'locations' => [
                    'src/CodingAgent/Agent/Definition/AgentDefinitionDTO.php',
                    'src/CodingAgent/Agent/Definition/AgentDefinitionParser.php',
                    'src/Tui/Listener/AgentPocCommandHandler.php',
                ],
            ],
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ];
        file_put_contents(
            $artifactPath,
            json_encode($artifact, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            \LOCK_EX,
        );

        // Add a synthetic retrieve event
        $existing = $this->loadEventsFromFile($this->childDir);
        $count = \count($existing);
        $lastSeq = $count > 0 ? $existing[$count - 1]['seq'] : 0;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing[] = [
            'schema_version' => 1,
            'run_id' => $this->childId,
            'seq' => $lastSeq + 1,
            'turn_no' => 1,
            'type' => 'artifact.created',
            'payload' => [
                'artifact_id' => 'poc-artifact-1',
                'path' => 'artifacts/agents/'.$this->childId.'/artifact-poc.json',
                'message_id' => \sprintf('poc-artifact-%d', $count + 1),
            ],
            'ts' => $now->format(\DATE_ATOM),
        ];
        $this->childEvents = $existing;
    }

    // ═══════════════════════════════════════════════════════════════
    // Compact status
    // ═══════════════════════════════════════════════════════════════

    private function updateCompactStatus(): void
    {
        $eventCount = \count($this->childEvents);
        $statusText = match ($this->childStatus) {
            'running' => \sprintf(
                'scout-poc running · %d events · open /agent-poc',
                $eventCount,
            ),
            'cancelled' => \sprintf(
                'scout-poc cancelled · %d events',
                $eventCount,
            ),
            default => \sprintf(
                'scout-poc %s · %d events',
                $this->childStatus,
                $eventCount,
            ),
        };

        $this->screen->setStatus(self::STATUS_KEY, $statusText);
    }

    // ═══════════════════════════════════════════════════════════════
    // File I/O (unchanged from prior commits)
    // ═══════════════════════════════════════════════════════════════

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

    private function readStatusFromState(): void
    {
        $statePath = $this->childDir.'/state.json';
        if (!file_exists($statePath)) {
            $this->childStatus = 'running';

            return;
        }

        $content = file_get_contents($statePath);
        if (false === $content) {
            $this->childStatus = 'running';

            return;
        }

        $state = json_decode($content, true);
        if (\is_array($state)) {
            $this->childStatus = $state['status'] ?? 'running';
        }
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
                    'status' => $this->childStatus,
                    'launch_mode' => 'background',
                    'depth' => 1,
                    'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                    'completed_at' => 'cancelled' === $this->childStatus
                        ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM)
                        : null,
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
            'status' => $this->childStatus,
            'agent_name' => 'scout',
            'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ];

        file_put_contents($path, json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }
}
