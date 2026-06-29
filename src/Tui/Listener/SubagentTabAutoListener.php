<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabInputModeEnum;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * POC: Auto-detects subagent artifacts while running and opens live tabs.
 *
 * On each TUI tick, scans the parent state transcript for subagent tool
 * result blocks with `subagent_progress` metadata.
 *
 * LIVING RUNNING subagents:
 *   - Detects blocks where `subagent_progress.status` is 'running'
 *   - Creates a tab immediately and populates it from available child events
 *   - Uses an ISOLATED real projection pipeline (fresh TranscriptProjector +
 *     fresh TranscriptProjectionState) so the shared parent projector state
 *     is never corrupted.
 *   - On subsequent ticks, re-reads child events via AgentSessionClient::events()
 *     and re-projects through the isolated pipeline
 *   - Detects terminal state from child run events (run.completed/failed/cancelled)
 *     and marks the tab as complete
 *   - Auto-returns to parent when the active child tab completes
 *
 * COMPLETED subagents (subagent_final=true):
 *   - Detects blocks where `subagent_final` is true
 *   - Creates a read-only tab with projected child events
 *
 * Tab input mode: Subagent artifact tabs are ReadOnly — no submit/cancel/model
 * controls. The user must switch to the parent tab (index 1) to interact with
 * the runtime. The editor/footer shows a status indicator when a ReadOnly tab
 * is active.
 *
 * @see TabRoutingListener for full blocker documentation
 */
final class SubagentTabAutoListener implements TuiListenerRegistrar
{
    /**
     * Track opened artifacts and their latest status.
     *
     * The key is artifact_id. The value is the progress status string
     * ('running', 'completed', 'failed', 'cancelled') so we can detect
     * lifecycle transitions from parent transcript blocks.
     *
     * @var array<string, string>
     */
    private array $openedArtifacts = [];

    /**
     * Track artifact IDs that have reached terminal state in child events.
     *
     * Key is artifact_id, value is true once terminal.
     *
     * @var array<string, true>
     */
    private array $terminalFromChildEvents = [];

    /**
     * Track artifact IDs where we auto-switched to the child tab on creation.
     *
     * Prevents auto-switching to the same tab again if it is re-detected.
     *
     * @var array<string, true>
     */
    private array $autoSwitchedTo = [];

    /**
     * Track artifact IDs where we auto-returned to the parent tab on completion.
     *
     * Prevents auto-returning multiple times for the same artifact.
     *
     * @var array<string, true>
     */
    private array $autoReturnedFrom = [];

    public function __construct(
        private readonly AgentSessionClient $client,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tabService = $context->tabService;
        $screen = $context->screen;
        $terminalFromChildEvents = &$this->terminalFromChildEvents;
        $autoSwitchedTo = &$this->autoSwitchedTo;
        $autoReturnedFrom = &$this->autoReturnedFrom;

        $context->ticks->add(function () use (
            $tabService,
            $screen,
            &$terminalFromChildEvents,
            &$autoSwitchedTo,
            &$autoReturnedFrom,
            $context,
        ): ?bool {
            $parentState = $context->state;
            $parentRunId = $parentState->sessionId;

            if ('' === $parentRunId || null === $tabService) {
                return null;
            }

            foreach ($parentState->transcript as $block) {
                $artifact = self::detectSubagentArtifact($block, $this->openedArtifacts);
                if (null === $artifact) {
                    continue;
                }

                $artifactId = $artifact['artifact_id'];
                $agentRunId = $artifact['agent_run_id'];
                $agentName = $artifact['agent_name'];
                $status = $artifact['status'];
                $isFinal = $artifact['is_final'];

                // Check if this artifact already has a tab
                $existingTab = self::findTabByArtifactId($tabService, $artifactId);

                if (null === $existingTab) {
                    // NEW artifact — create tab
                    $newTabIndex = $tabService->count();
                    $this->openArtifactTab(
                        tabService: $tabService,
                        screen: $screen,
                        client: $this->client,
                        eventDispatcher: $this->eventDispatcher,
                        parentRunId: $parentRunId,
                        artifactId: $artifactId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        isTerminal: $isFinal,
                        status: $status,
                    );

                    // Auto-switch to the new tab (only once per artifact)
                    if (!isset($autoSwitchedTo[$artifactId])) {
                        $autoSwitchedTo[$artifactId] = true;
                        $tabService->switchTo($newTabIndex);
                        $activeState = $tabService->activeState();
                        if (null !== $activeState) {
                            $screen->setTranscriptBlocks($activeState->transcript);
                        }
                    }

                    if ($isFinal) {
                        $terminalFromChildEvents[$artifactId] = true;
                    }
                } else {
                    // EXISTING tab — update if not yet terminal
                    $wasTerminal = isset($terminalFromChildEvents[$artifactId]);
                    if ($wasTerminal) {
                        continue;
                    }

                    $existingState = $existingTab->state;

                    // If the parent block says it's now final, mark as terminal
                    if ($isFinal) {
                        $existingState->activity = self::terminalActivity($status);
                        $terminalFromChildEvents[$artifactId] = true;

                        // Update tab label to remove running indicator
                        self::updateTabLabel($existingTab, $status);

                        // Auto-return: if active tab is this completed child, switch to parent
                        self::autoReturnIfActive(
                            tabService: $tabService,
                            screen: $screen,
                            artifactId: $artifactId,
                            autoReturnedFrom: $autoReturnedFrom,
                        );

                        continue;
                    }

                    // Re-read child events and project through isolated pipeline
                    // Materialize once: AgentSessionClient::events() may return a single-pass Generator
                    $freshEvents = self::iterableToArray($this->client->events($agentRunId));
                    $freshBlocks = self::projectChildEvents($freshEvents, $agentRunId, $this->eventDispatcher);

                    if ([] !== $freshBlocks) {
                        $existingState->transcript = $freshBlocks;
                    } else {
                        // No child events yet — show status placeholder
                        $existingState->transcript = [
                            self::buildStatusBlock($agentRunId, $agentName, $status),
                        ];
                    }

                    // Check child events for terminal state
                    $isChildTerminal = self::eventsContainTerminal($freshEvents);
                    if ($isChildTerminal) {
                        $terminalStatus = self::terminalStatusFromEvents($freshEvents);
                        $existingState->activity = self::terminalActivity($terminalStatus);
                        $terminalFromChildEvents[$artifactId] = true;

                        // Update tab label to remove running indicator
                        self::updateTabLabel($existingTab, $terminalStatus);

                        // Auto-return: if active tab is this completed child, switch to parent
                        self::autoReturnIfActive(
                            tabService: $tabService,
                            screen: $screen,
                            artifactId: $artifactId,
                            autoReturnedFrom: $autoReturnedFrom,
                        );
                    }

                    // If this tab is currently active, update the screen
                    if ($tabService->active()?->id === $existingTab->id) {
                        $screen->setTranscriptBlocks($existingState->transcript);
                    }
                }

                $this->openedArtifacts[$artifactId] = $status;
            }

            return null;
        });
    }

    /**
     * Scan a transcript block for subagent artifact data.
     *
     * Returns artifact data when the block is a ToolResult with
     * `subagent_progress` metadata containing valid `artifact_id`
     * and `agent_run_id`, regardless of whether the subagent is
     * still running or has completed.
     *
     * For already-opened artifacts, the method returns data anyway
     * so the caller can update the existing tab (status may have
     * changed from 'running' to terminal).
     *
     * Made public static for testability.
     *
     * @param array<string, string> $openedArtifacts
     *
     * @return array{artifact_id: string, agent_run_id: string, agent_name: string, status: string, is_final: bool}|null
     */
    public static function detectSubagentArtifact(
        TranscriptBlock $block,
        array &$openedArtifacts,
    ): ?array {
        // Only ToolResult blocks
        if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
            return null;
        }

        $progress = $block->meta['subagent_progress'] ?? null;
        if (!\is_array($progress)) {
            return null;
        }

        $artifactId = $progress['artifact_id'] ?? null;
        $agentRunId = $progress['agent_run_id'] ?? null;
        $agentName = $progress['agent_name'] ?? null;

        if (!\is_string($artifactId) || '' === $artifactId
            || !\is_string($agentRunId) || '' === $agentRunId
        ) {
            return null;
        }

        // Get the status from progress payload (running, completed, etc.)
        $status = $progress['status'] ?? 'running';
        if (!\is_string($status)) {
            $status = 'running';
        }

        // Check whether the block signals final completion
        $isFinal = (bool) ($block->meta['subagent_final'] ?? false);

        return [
            'artifact_id' => $artifactId,
            'agent_run_id' => $agentRunId,
            'agent_name' => \is_string($agentName) ? $agentName : 'subagent',
            'status' => $status,
            'is_final' => $isFinal,
        ];
    }

    /**
     * Build transcript blocks from child RuntimeEvents.
     *
     * Kept for backward compatibility with existing tests.
     * Production code uses projectChildEvents() which runs the real
     * projection pipeline with DI-registered subscribers.
     *
     * @param iterable<RuntimeEvent> $events
     *
     * @return list<TranscriptBlock>
     */
    public static function buildBlocksFromEvents(
        iterable $events,
        TranscriptBlockFactory $blockFactory,
        string $runId,
    ): array {
        $blocks = [];
        $seq = 0;

        foreach ($events as $event) {
            if (!$event instanceof RuntimeEvent) {
                continue;
            }

            ++$seq;
            $type = $event->type;
            $payload = $event->payload;

            switch ($type) {
                case RuntimeEventTypeEnum::RunStarted->value:
                    $blocks[] = new TranscriptBlock(
                        id: $runId.'_run_start',
                        kind: TranscriptBlockKindEnum::System,
                        runId: $runId,
                        seq: $seq,
                        text: 'Run started',
                        meta: [],
                    );
                    break;

                case RuntimeEventTypeEnum::RunCompleted->value:
                    $reason = (string) ($payload['reason'] ?? 'completed');
                    $blocks[] = new TranscriptBlock(
                        id: $runId.'_run_completed',
                        kind: TranscriptBlockKindEnum::System,
                        runId: $runId,
                        seq: $seq,
                        text: \sprintf('Run completed: %s', $reason),
                        meta: [],
                    );
                    break;

                case RuntimeEventTypeEnum::RunFailed->value:
                    $reason = (string) ($payload['reason'] ?? 'failed');
                    $blocks[] = new TranscriptBlock(
                        id: $runId.'_run_failed',
                        kind: TranscriptBlockKindEnum::System,
                        runId: $runId,
                        seq: $seq,
                        text: \sprintf('Run failed: %s', $reason),
                        meta: ['is_error' => true],
                    );
                    break;

                case RuntimeEventTypeEnum::RunCancelled->value:
                    $blocks[] = new TranscriptBlock(
                        id: $runId.'_run_cancelled',
                        kind: TranscriptBlockKindEnum::Cancelled,
                        runId: $runId,
                        seq: $seq,
                        text: 'Run cancelled',
                        meta: [],
                    );
                    break;

                case RuntimeEventTypeEnum::AssistantMessageCompleted->value:
                    $blocks[] = self::buildAssistantBlock($payload, $runId, $seq);
                    break;

                case RuntimeEventTypeEnum::ToolExecutionStarted->value:
                    $toolName = (string) ($payload['tool_name'] ?? 'unknown');
                    $toolCallId = (string) ($payload['tool_call_id'] ?? $toolName);
                    $blocks[] = new TranscriptBlock(
                        id: 'tool_call_'.$toolCallId,
                        kind: TranscriptBlockKindEnum::ToolCall,
                        runId: $runId,
                        seq: $seq,
                        text: $toolName,
                        meta: [
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                        ],
                        streaming: true,
                    );
                    break;

                case RuntimeEventTypeEnum::ToolExecutionCompleted->value:
                    $toolName = (string) ($payload['tool_name'] ?? 'unknown');
                    $toolCallId = (string) ($payload['tool_call_id'] ?? $toolName);
                    $result = (string) ($payload['result'] ?? $payload['output'] ?? '(completed)');
                    $blocks[] = new TranscriptBlock(
                        id: 'tool_result_'.$toolCallId,
                        kind: TranscriptBlockKindEnum::ToolResult,
                        runId: $runId,
                        seq: $seq,
                        text: self::truncateToolResult($result),
                        meta: [
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'is_error' => false,
                            'result' => self::truncateToolResult($result),
                        ],
                    );
                    break;

                case RuntimeEventTypeEnum::ToolExecutionFailed->value:
                    $toolName = (string) ($payload['tool_name'] ?? 'unknown');
                    $toolCallId = (string) ($payload['tool_call_id'] ?? $toolName);
                    $errorText = (string) ($payload['error'] ?? $payload['result'] ?? 'Failed');
                    $blocks[] = new TranscriptBlock(
                        id: 'tool_result_'.$toolCallId,
                        kind: TranscriptBlockKindEnum::ToolResult,
                        runId: $runId,
                        seq: $seq,
                        text: self::truncateToolResult($errorText),
                        meta: [
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'is_error' => true,
                            'result' => self::truncateToolResult($errorText),
                        ],
                    );
                    break;

                case RuntimeEventTypeEnum::ToolExecutionCancelled->value:
                    $toolName = (string) ($payload['tool_name'] ?? 'unknown');
                    $toolCallId = (string) ($payload['tool_call_id'] ?? $toolName);
                    $blocks[] = new TranscriptBlock(
                        id: 'tool_result_'.$toolCallId,
                        kind: TranscriptBlockKindEnum::Cancelled,
                        runId: $runId,
                        seq: $seq,
                        text: \sprintf('Tool %s cancelled', $toolName),
                        meta: [
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                        ],
                    );
                    break;

                case RuntimeEventTypeEnum::HumanInputRequested->value:
                    $blocks[] = new TranscriptBlock(
                        id: $runId.'_waiting_human_'.$seq,
                        kind: TranscriptBlockKindEnum::System,
                        runId: $runId,
                        seq: $seq,
                        text: 'Waiting for human input (not available in read-only tab)',
                        meta: [],
                    );
                    break;

                default:
                    // Skip unmapped event types
                    break;
            }
        }

        return $blocks;
    }

    /**
     * Project child RuntimeEvents into transcript blocks using an isolated
     * real projection pipeline.
     *
     * Creates a fresh TranscriptProjectionState and TranscriptProjector so
     * the shared parent projector state is never touched. This gives child
     * tabs the same rich rendering as the main transcript (assistant messages,
     * tool calls/results, cancellations, etc.) instead of the crude manual
     * block mapping from earlier POC iterations.
     *
     * @param list<RuntimeEvent> $childEvents
     *
     * @return list<TranscriptBlock>
     */
    public static function projectChildEvents(
        array $childEvents,
        string $runId,
        EventDispatcherInterface $eventDispatcher,
    ): array {
        $state = new TranscriptProjectionState();
        $projector = new TranscriptProjector($eventDispatcher, $state);

        foreach ($childEvents as $event) {
            if (!$event instanceof RuntimeEvent) {
                continue;
            }

            $projector->accept($event->toArray());
        }

        return $projector->blocks();
    }

    /**
     * Auto-return to parent tab when the active tab completes.
     *
     * Only fires once per artifact to avoid repeated auto-return loops
     * (e.g. when parent transcript re-detects the same terminal artifact).
     *
     * Appends a system block to the parent transcript so the user sees
     * a clear "Subagent completed" message when they return.
     *
     * @param array<string, true> $autoReturnedFrom
     */
    private static function autoReturnIfActive(
        TabService $tabService,
        ChatScreen $screen,
        string $artifactId,
        array &$autoReturnedFrom,
    ): void {
        // Only auto-return if the completing tab is currently active
        $activeTab = $tabService->active();
        if (null === $activeTab || $activeTab->id !== 'artifact-'.$artifactId) {
            return;
        }

        // Only auto-return once per artifact
        if (isset($autoReturnedFrom[$artifactId])) {
            return;
        }
        $autoReturnedFrom[$artifactId] = true;

        // Determine terminal status from the completing tab's state
        $terminalStatus = match ($activeTab->state->activity) {
            RunActivityStateEnum::Failed => 'failed',
            RunActivityStateEnum::Cancelled => 'cancelled',
            default => 'completed',
        };

        // Switch to parent tab (index 0)
        $tabService->switchTo(0);
        $parentTab = $tabService->active();
        if (null === $parentTab) {
            return;
        }

        // Append a system status block to the parent transcript
        $parentSeq = \count($parentTab->state->transcript) + 1;
        $parentTab->state->transcript[] = new TranscriptBlock(
            id: 'auto_return_'.$artifactId,
            kind: TranscriptBlockKindEnum::System,
            runId: $parentTab->runId,
            seq: $parentSeq,
            text: \sprintf('⟳ Subagent %s — final result returned above.', $terminalStatus),
            meta: ['auto_return' => true],
        );

        $screen->setTranscriptBlocks($parentTab->state->transcript);
    }

    /**
     * Build an assistant transcript block from an llm_step_completed payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function buildAssistantBlock(array $payload, string $runId, int $seq): TranscriptBlock
    {
        $inner = $payload['payload'] ?? $payload;
        if (!\is_array($inner)) {
            $inner = $payload;
        }

        // Extract tool call info
        $toolCalls = $inner['tool_calls'] ?? [];
        $toolCallSummary = '';
        if (\is_array($toolCalls) && [] !== $toolCalls) {
            $names = [];
            foreach ($toolCalls as $tc) {
                if (\is_array($tc) && isset($tc['name']) && \is_string($tc['name'])) {
                    $names[] = $tc['name'];
                }
            }
            if ([] !== $names) {
                $toolCallSummary = ' → call: '.implode(', ', $names);
            }
        }

        // Extract text content
        $textContent = (string) ($payload['text'] ?? '');
        if ('' === $textContent) {
            $content = $inner['content'] ?? '';
            if (\is_array($content)) {
                $textParts = [];
                foreach ($content as $part) {
                    if (\is_array($part) && 'text' === ($part['type'] ?? '') && \is_string($part['text'] ?? null)) {
                        $textParts[] = $part['text'];
                    }
                }
                $textContent = implode('', $textParts);
            } elseif (\is_string($content)) {
                $textContent = $content;
            }
        }

        $textContent = self::truncateToolResult($textContent);
        $displayText = '' !== $textContent ? $textContent : '(assistant response)';
        if ('' !== $toolCallSummary) {
            $displayText .= $toolCallSummary;
        }

        return new TranscriptBlock(
            id: $runId.'_llm_'.$seq,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: $runId,
            seq: $seq,
            text: $displayText,
            meta: [],
        );
    }

    /**
     * Truncate tool result text to prevent massive blocks.
     */
    private static function truncateToolResult(string $text): string
    {
        $maxLength = 500;
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'…';
    }

    /**
     * Update a tab's label to remove the running indicator on terminal.
     */
    private static function updateTabLabel(TabDefinition $tab, string $status): void
    {
        $shortId = substr($tab->runId, 0, 8);
        $statusMark = match ($status) {
            'completed' => ' ✓',
            'failed' => ' ✗',
            'cancelled' => ' ⊘',
            default => ' ✓',
        };
        $tab->label = \sprintf('Sub %s%s', $shortId, $statusMark);
    }

    /**
     * Convert an iterable to an array for multiple consumption.
     *
     * @template T
     *
     * @param iterable<T> $iterable
     *
     * @return list<T>
     */
    private static function iterableToArray(iterable $iterable): array
    {
        if ($iterable instanceof \Traversable) {
            return iterator_to_array($iterable, false);
        }

        return $iterable;
    }

    /**
     * Build a status placeholder block for when no child events are available yet.
     */
    private static function buildStatusBlock(string $runId, string $agentName, string $status): TranscriptBlock
    {
        $displayStatus = match ($status) {
            'running' => 'Running…',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            default => $status,
        };

        return new TranscriptBlock(
            id: $runId.'_status',
            kind: TranscriptBlockKindEnum::System,
            runId: $runId,
            seq: 1,
            text: \sprintf('Subagent "%s": %s', $agentName, $displayStatus),
            meta: [],
        );
    }

    /**
     * Check if a collection of child events contains a terminal run event.
     *
     * @param iterable<RuntimeEvent> $events
     */
    private static function eventsContainTerminal(iterable $events): bool
    {
        foreach ($events as $event) {
            if (!$event instanceof RuntimeEvent) {
                continue;
            }

            $type = $event->type;
            if (RuntimeEventTypeEnum::RunCompleted->value === $type
                || RuntimeEventTypeEnum::RunFailed->value === $type
                || RuntimeEventTypeEnum::RunCancelled->value === $type
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract terminal status string from child events.
     *
     * @param iterable<RuntimeEvent> $events
     */
    private static function terminalStatusFromEvents(iterable $events): string
    {
        foreach ($events as $event) {
            if (!$event instanceof RuntimeEvent) {
                continue;
            }

            $status = match ($event->type) {
                RuntimeEventTypeEnum::RunCompleted->value => 'completed',
                RuntimeEventTypeEnum::RunFailed->value => 'failed',
                RuntimeEventTypeEnum::RunCancelled->value => 'cancelled',
                default => null,
            };

            if (null !== $status) {
                return $status;
            }
        }

        return 'completed';
    }

    /**
     * Map a status string to a RunActivityStateEnum.
     */
    private static function terminalActivity(string $status): RunActivityStateEnum
    {
        return match ($status) {
            'failed' => RunActivityStateEnum::Failed,
            'cancelled' => RunActivityStateEnum::Cancelled,
            default => RunActivityStateEnum::Completed,
        };
    }

    /**
     * Open a tab for a subagent artifact.
     *
     * When the subagent is still running, populates the tab with projected
     * child events using an isolated real projection pipeline. Falls back
     * to a status placeholder only when no child events are available yet.
     *
     * Tab is created in ReadOnly mode — no submit/cancel/model controls.
     */
    private function openArtifactTab(
        TabService $tabService,
        ChatScreen $screen,
        AgentSessionClient $client,
        EventDispatcherInterface $eventDispatcher,
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $agentName,
        bool $isTerminal,
        string $status,
    ): void {
        $childState = new TuiSessionState($agentRunId, false);

        // Read child events that have been written so far
        // Convert to array: events() may return a single-pass Generator
        $childEventsRaw = $client->events($agentRunId);
        $childEvents = self::iterableToArray($childEventsRaw);
        $blocks = self::projectChildEvents($childEvents, $agentRunId, $eventDispatcher);

        if ([] !== $blocks) {
            $childState->transcript = $blocks;
        } else {
            // No child events yet — show a status placeholder
            $childState->transcript = [
                self::buildStatusBlock($agentRunId, $agentName, $status),
            ];
        }

        // Determine activity state (reuses $childEvents array)
        if ($isTerminal || self::eventsContainTerminal($childEvents)) {
            $terminalStatus = self::terminalStatusFromEvents($childEvents);
            $childState->activity = self::terminalActivity('' !== $terminalStatus ? $terminalStatus : $status);
        } elseif ('running' === $status) {
            $childState->activity = RunActivityStateEnum::Running;
        } else {
            $childState->activity = RunActivityStateEnum::Completed;
        }

        // No interactive handle — read-only tab
        $childState->handle = null;

        // Tab label shows status
        $statusIndicator = 'running' === $status || $childState->activity->isActive() ? ' ▶' : '';
        $tabLabel = \sprintf('Sub %s%s', substr($agentRunId, 0, 8), $statusIndicator);

        $tabService->addTab(new TabDefinition(
            id: 'artifact-'.$artifactId,
            label: $tabLabel,
            runId: $agentRunId,
            state: $childState,
            inputMode: TabInputModeEnum::ReadOnly,
        ));
    }

    /**
     * Find an existing tab by artifact ID prefix.
     */
    private static function findTabByArtifactId(TabService $tabService, string $artifactId): ?TabDefinition
    {
        $tabId = 'artifact-'.$artifactId;

        return $tabService->findTabById($tabId);
    }
}
