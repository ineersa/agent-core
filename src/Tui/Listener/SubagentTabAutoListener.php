<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;

/**
 * POC: Auto-detects subagent artifacts while running and opens live tabs.
 *
 * On each TUI tick, scans the parent state transcript for subagent tool
 * result blocks with `subagent_progress` metadata.
 *
 * LIVING RUNNING subagents:
 *   - Detects blocks where `subagent_progress.status` is 'running'
 *   - Creates a tab immediately and populates it from available child events
 *   - On subsequent ticks, re-reads child events via AgentSessionClient::events()
 *     and updates the tab transcript with new blocks
 *   - Detects terminal state from child run events (run.completed/failed/cancelled)
 *     and marks the tab as complete
 *
 * COMPLETED subagents:
 *   - Detects blocks where `subagent_final` is true
 *   - Creates a read-only tab with all available child events
 *
 * Auto-switches to the new subagent tab when first created so the user
 * immediately sees the running subagent's output.
 *
 * Read-only because:
 *   - The subagent runs foreground (blocks parent LLM turn)
 *   - No child RunHandle is exposed for interactive input
 *   - Child events are parent-artifact scoped, not independently accessible
 *
 * Blocks are built from child RuntimeEvents manually (not through the shared
 * TranscriptProjector singleton) to avoid corrupting the parent's projected
 * block state.
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

    public function __construct(
        private readonly AgentSessionClient $client,
        private readonly TranscriptBlockFactory $blockFactory,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tabService = $context->tabService;
        $screen = $context->screen;
        $client = $this->client;
        $blockFactory = $this->blockFactory;
        $openedArtifacts = &$this->openedArtifacts;
        $terminalFromChildEvents = &$this->terminalFromChildEvents;

        $context->ticks->add(static function () use (
            $tabService,
            $screen,
            $client,
            $blockFactory,
            &$openedArtifacts,
            &$terminalFromChildEvents,
            $context,
        ): ?bool {
            $parentState = $context->state;
            $parentRunId = $parentState->sessionId;

            if ('' === $parentRunId || null === $tabService) {
                return null;
            }

            foreach ($parentState->transcript as $block) {
                $artifact = self::detectSubagentArtifact($block, $openedArtifacts);
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
                    // NEW artifact — create tab and auto-switch
                    $newTabIndex = $tabService->count();
                    self::openArtifactTab(
                        tabService: $tabService,
                        screen: $screen,
                        client: $client,
                        blockFactory: $blockFactory,
                        parentRunId: $parentRunId,
                        artifactId: $artifactId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        isTerminal: $isFinal,
                        status: $status,
                    );

                    // Auto-switch so user sees the subagent tab immediately
                    $tabService->switchTo($newTabIndex);
                    $activeState = $tabService->activeState();
                    if (null !== $activeState) {
                        $screen->setTranscriptBlocks($activeState->transcript);
                    }

                    if ($isFinal) {
                        $terminalFromChildEvents[$artifactId] = true;
                    }
                } else {
                    // EXISTING tab — update if not yet terminal
                    if (isset($terminalFromChildEvents[$artifactId])) {
                        continue;
                    }

                    $existingState = $existingTab->state;

                    // If the parent block says it's now final, mark as terminal
                    if ($isFinal) {
                        $existingState->activity = self::terminalActivity($status);
                        $terminalFromChildEvents[$artifactId] = true;

                        continue;
                    }

                    // Re-read child events to see if we have new data
                    // Materialize once: AgentSessionClient::events() may return a single-pass Generator
                    $freshEvents = self::iterableToArray($client->events($agentRunId));
                    $freshBlocks = self::buildBlocksFromEvents($freshEvents, $blockFactory, $agentRunId);

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
                    }

                    // If this tab is currently active, update the screen
                    if ($tabService->active()?->id === $existingTab->id) {
                        $screen->setTranscriptBlocks($existingState->transcript);
                    }
                }

                $openedArtifacts[$artifactId] = $status;
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
     * Converts child agent events into display blocks without using the
     * shared TranscriptProjector (which would corrupt the parent's projected
     * block state).
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
     * Convert an iterable to an array for multiple consumption.
     *
     * AgentSessionClient::events() may return a single-pass Generator
     * (yield/yield from), so we must materialize the iterable before
     * passing it to multiple consumers.
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
     * When the subagent is still running, populates the tab with whatever
     * child events have been written so far, adding a status placeholder
     * if no events are available yet.
     */
    private static function openArtifactTab(
        TabService $tabService,
        ChatScreen $screen,
        AgentSessionClient $client,
        TranscriptBlockFactory $blockFactory,
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
        $blocks = self::buildBlocksFromEvents($childEvents, $blockFactory, $agentRunId);

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
            isRun: false, // read-only artifact tab, not a live run
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
