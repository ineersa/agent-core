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
use Ineersa\Tui\Transcript\TranscriptBlockFactory;

/**
 * POC: Auto-detects completed subagent artifacts and opens read-only tabs.
 *
 * On each TUI tick, scans the parent state transcript for subagent tool
 * result blocks with `subagent_final` metadata. When a new one is found,
 * it creates a read-only tab that:
 *
 *   1. Reads the child run events via AgentSessionClient::events(agentRunId)
 *      (routes through ChildAwareEventStore to the artifact's events.jsonl)
 *   2. Converts them into transcript blocks for display
 *   3. Registers the resulting tab in TabService
 *
 * The tab is read-only because:
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
    /** @var array<string, true> artifact IDs already opened as tabs */
    private array $openedArtifacts = [];

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

        $context->ticks->add(static function () use (
            $tabService,
            $screen,
            $client,
            $blockFactory,
            &$openedArtifacts,
            $context,
        ): ?bool {
            // Only scan the parent state's transcript (not active tab)
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

                // Found a new subagent artifact — create a read-only tab
                self::openArtifactTab(
                    tabService: $tabService,
                    screen: $screen,
                    client: $client,
                    blockFactory: $blockFactory,
                    parentRunId: $parentRunId,
                    artifactId: $artifact['artifact_id'],
                    agentRunId: $artifact['agent_run_id'],
                    agentName: $artifact['agent_name'],
                );

                $openedArtifacts[$artifact['artifact_id']] = true;
            }

            return null;
        });
    }

    /**
     * Scan a transcript block for subagent artifact data.
     *
     * Returns ['artifact_id', 'agent_run_id', 'agent_name'] when the
     * block is a completed subagent tool result not yet opened as a tab.
     *
     * Made public static for testability.
     *
     * @param array<string, true> $openedArtifacts
     *
     * @return array{artifact_id: string, agent_run_id: string, agent_name: string}|null
     */
    public static function detectSubagentArtifact(
        TranscriptBlock $block,
        array &$openedArtifacts,
    ): ?array {
        // Only ToolResult blocks with subagent_final flag
        if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
            return null;
        }

        $progress = $block->meta['subagent_progress'] ?? null;
        if (!\is_array($progress)) {
            return null;
        }

        // Must be terminal (completed, failed, or cancelled)
        $isFinal = (bool) ($block->meta['subagent_final'] ?? false);
        if (!$isFinal) {
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

        // Already opened
        if (isset($openedArtifacts[$artifactId])) {
            return null;
        }

        return [
            'artifact_id' => $artifactId,
            'agent_run_id' => $agentRunId,
            'agent_name' => \is_string($agentName) ? $agentName : 'subagent',
        ];
    }

    /**
     * Build transcript blocks from child RuntimeEvents.
     *
     * Converts child agent events into display blocks without using the
     * shared TranscriptProjector (which would corrupt the parent's projected
     * block state). Each RuntimeEvent type maps to a TranscriptBlock:
     *
     *   run.started              → system ("Run started")
     *   llm_step_completed       → assistant (text summary)
     *   tool_execution.started   → tool call (tool name)
     *   tool_execution.completed → tool result (result text)
     *   tool_execution.failed    → tool result (error)
     *   tool_execution.cancelled → cancelled
     *   agent_end                → system (reason)
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
     */
    /**
     * @param array<string, mixed> $payload
     */
    private static function buildAssistantBlock(array $payload, string $runId, int $seq): TranscriptBlock
    {
        // Extract inner payload if nested
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
        // Priority: explicit 'text' key (from RuntimeEventTranslator) > nested 'content' > 'content' array walk
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

    private static function truncateToolResult(string $text): string
    {
        $maxLength = 500;
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'…';
    }

    /**
     * Open a read-only tab for a subagent artifact.
     *
     * Reads child run events via AgentSessionClient::events() (routes
     * through ChildAwareEventStore to the artifact's events.jsonl),
     * converts them to transcript blocks manually, and registers the
     * tab.
     */
    private static function openArtifactTab(
        TabService $tabService,
        \Ineersa\Tui\Screen\ChatScreen $screen,
        AgentSessionClient $client,
        TranscriptBlockFactory $blockFactory,
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $agentName,
    ): void {
        // 1. Create child state
        $childState = new TuiSessionState($agentRunId, false);

        // 2. Read child events
        $childEvents = $client->events($agentRunId);

        // 3. Build blocks manually (avoid shared projector)
        $blocks = self::buildBlocksFromEvents($childEvents, $blockFactory, $agentRunId);

        if ([] !== $blocks) {
            $childState->transcript = $blocks;
        } else {
            $childState->transcript = [
                $blockFactory->system(
                    runId: $agentRunId,
                    text: \sprintf(
                        'Subagent "%s" (artifact: %s) completed — no child events found.',
                        $agentName,
                        $artifactId,
                    ),
                    seq: 1,
                ),
            ];
        }

        // 4. No interactive handle — read-only tab
        $childState->activity = RunActivityStateEnum::Completed;
        $childState->handle = null;

        // 5. Create tab definition
        $tabLabel = \sprintf('Sub %s', substr($agentRunId, 0, 8));
        $tabService->addTab(new TabDefinition(
            id: 'artifact-'.$artifactId,
            label: $tabLabel,
            runId: $agentRunId,
            state: $childState,
            isRun: false, // read-only artifact tab, not a live run
        ));

        // 6. Don't auto-switch — parent stays active. User can /tab N to view.
    }
}
