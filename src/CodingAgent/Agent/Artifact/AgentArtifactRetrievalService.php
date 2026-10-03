<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\RunPresentationDTO;
use Ineersa\CodingAgent\Session\History\RunPresentationReader;
use Psr\Log\LoggerInterface;

/**
 * Resolves parent-scoped subagent artifacts and renders bounded, privacy-safe
 * retrieval output for the {@see \Ineersa\CodingAgent\Agent\Tool\AgentRetrieveTool}.
 */
final class AgentArtifactRetrievalService
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    public const int HISTORY_SUMMARY_CHARS = 240;

    private const string TEMPLATE_HANDOFF_HEADER = <<<'MD'
# Subagent handoff

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}
- status: {status}
MD;

    private const string TEMPLATE_METADATA = <<<'MD'
# Subagent artifact metadata

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}

- status: {status}
- created_at: {created_at}
{started_at_line}{completed_at_line}{summary_line}{failure_reason_line}{needs_clarification_line}{child_state_section}{event_log_section}
MD;

    private const string TEMPLATE_EVENTS_HEADER = <<<'MD'
# Subagent recent events

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}

{summary_line}
MD;

    private const string TEMPLATE_HISTORY_HEADER = <<<'MD'
# Subagent message history (bounded)

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}

{summary_line}
MD;

    private const string TEMPLATE_HANDOFF_HISTORY_HEADER = <<<'MD'
# Subagent handoff history

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}
MD;

    private const string TEMPLATE_DEBUG = <<<'MD'
# Subagent artifact debug paths

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}

- status: {status}
- artifact_dir: {artifact_dir}
- metadata_path: {metadata_path}
- events_path: {events_path}
MD;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly RunPresentationReader $presentationReader,
        private readonly EventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
        private readonly ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec,
    ) {
    }

    public function retrieve(string $parentRunId, AgentRetrieveArgumentsDTO $args): string
    {
        if ('' === trim($parentRunId)) {
            throw new ToolCallException('agent_retrieve requires an active parent run context.', retryable: false);
        }

        try {
            $mode = $args->resolvedMode();
            $limit = $args->resolvedLimit(self::DEFAULT_LIMIT, self::MAX_LIMIT);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }

        $entry = $this->resolveEntry(
            $parentRunId,
            $args->trimmedArtifactId(),
            $args->trimmedAgentRunId(),
        );

        $childState = match ($mode) {
            AgentRetrieveModeEnum::Metadata => $this->loadChildPresentation($entry, 0),
            AgentRetrieveModeEnum::History => $this->loadChildPresentation($entry, $limit),
            default => null,
        };

        return match ($mode) {
            AgentRetrieveModeEnum::Handoff => $this->renderHandoff($entry),
            AgentRetrieveModeEnum::Metadata => $this->renderMetadata($entry, $childState),
            AgentRetrieveModeEnum::Events => $this->renderEvents($entry, $limit),
            AgentRetrieveModeEnum::History => $this->renderHistory($entry, $limit, $childState),
            AgentRetrieveModeEnum::HandoffHistory => $this->renderHandoffHistory($entry, $args->trimmedHandoffId()),
            AgentRetrieveModeEnum::Debug => $this->renderDebug($entry),
        };
    }

    private function resolveEntry(string $parentRunId, ?string $artifactId, ?string $agentRunId): AgentArtifactEntryDTO
    {
        $byArtifact = null;
        $byRun = null;

        if (null !== $artifactId) {
            try {
                $byArtifact = $this->artifactRegistry->get($parentRunId, $artifactId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false, hint: 'Use a simple artifact id such as agent_abc123 without path separators.');
            }

            if (null === $byArtifact) {
                throw new ToolCallException(\sprintf('Unknown artifact_id "%s" in the current parent session.', $artifactId), retryable: false, hint: 'List artifacts from subagent completions or use the artifact id from the subagent handoff header.');
            }
        }

        if (null !== $agentRunId) {
            try {
                $byRun = $this->artifactRegistry->findByAgentRunId($parentRunId, $agentRunId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false);
            }

            if (null === $byRun) {
                $located = $this->childRunDirectory->locate($agentRunId);
                if (null !== $located && $located->parentRunId !== $parentRunId) {
                    throw new ToolCallException(\sprintf('Child run "%s" belongs to a different parent session and cannot be retrieved from the current run.', $agentRunId), retryable: false);
                }

                throw new ToolCallException(\sprintf('Unknown agent_run_id "%s" for the current parent session.', $agentRunId), retryable: false);
            }
        }

        if (null !== $byArtifact && null !== $byRun) {
            if ($byArtifact->artifactId !== $byRun->artifactId || $byArtifact->agentRunId !== $byRun->agentRunId) {
                throw new ToolCallException('artifact_id and agent_run_id refer to different subagent artifacts in the current parent session.', retryable: false, hint: 'Provide only one identifier, or ensure both refer to the same child artifact.');
            }

            return $byArtifact;
        }

        return $byArtifact ?? $byRun ?? throw new ToolCallException('Unable to resolve subagent artifact.', retryable: false);
    }

    private function renderHandoff(AgentArtifactEntryDTO $entry): string
    {
        $handoff = $this->artifactRegistry->readHandoff($entry->parentRunId, $entry->artifactId);
        $header = $this->renderTemplate(self::TEMPLATE_HANDOFF_HEADER, $this->identityVars($entry) + [
            'status' => $entry->status->value,
        ]);

        if ('' === trim($handoff)) {
            return $header."\n\n_(No handoff content stored.)_";
        }

        return $header."\n\n".$handoff;
    }

    private function renderMetadata(AgentArtifactEntryDTO $entry, ?RunPresentationDTO $state): string
    {
        $vars = $this->identityVars($entry) + [
            'status' => $entry->status->value,
            'created_at' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'started_at_line' => null !== $entry->startedAt
                ? '- started_at: '.$entry->startedAt->format(\DateTimeInterface::ATOM)."\n"
                : '',
            'completed_at_line' => null !== $entry->completedAt
                ? '- completed_at: '.$entry->completedAt->format(\DateTimeInterface::ATOM)."\n"
                : '',
            'summary_line' => null !== $entry->summary && '' !== trim($entry->summary)
                ? '- summary: '.$this->truncateLine($entry->summary, 500)."\n"
                : '',
            'failure_reason_line' => null !== $entry->failureReason && '' !== trim($entry->failureReason)
                ? '- failure_reason: '.$this->truncateLine($entry->failureReason, 500)."\n"
                : '',
            'needs_clarification_line' => null !== $entry->needsClarification && '' !== trim($entry->needsClarification)
                ? '- needs_clarification: '.$this->truncateLine($entry->needsClarification, 500)."\n"
                : '',
            'child_state_section' => '',
            'event_log_section' => '',
        ];

        if (null !== $state) {
            $vars['child_state_section'] = implode("\n", [
                '',
                '## Child run state',
                '- run_status: '.$state->status->value,
                '- turn_no: '.\sprintf('%d', $state->turnNo),
                '- last_seq: '.\sprintf('%d', $state->lastSeq),
                '- message_count: '.\sprintf('%d', $state->messageCount),
                '- pending_tool_calls: '.\sprintf('%d', $state->pendingToolCallCount),
            ])."\n";
        }

        $eventCount = $state->eventCount ?? 0;
        if (null === $state) {
            foreach ($this->eventStore->rangeFor($entry->agentRunId, 1, \PHP_INT_MAX) as $event) {
                ++$eventCount;
            }
        }
        $vars['event_log_section'] = implode("\n", [
            '',
            '## Event log',
            '- event_count: '.\sprintf('%d', $eventCount),
        ]);

        return rtrim($this->renderTemplate(self::TEMPLATE_METADATA, $vars));
    }

    private function renderEvents(AgentArtifactEntryDTO $entry, int $limit): string
    {
        $eventCount = 0;
        $slice = [];
        foreach ($this->eventStore->rangeFor($entry->agentRunId, 1, \PHP_INT_MAX) as $event) {
            ++$eventCount;
            // Retain bounded sanitized strings, never decoded event payloads.
            $slice[] = \sprintf(
                '- seq=%d turn=%d type=%s at=%s — %s',
                $event->seq,
                $event->turnNo,
                $event->type,
                $event->createdAt->format(\DateTimeInterface::ATOM),
                $this->summarizeEvent($event),
            );
            if (\count($slice) > $limit) {
                array_shift($slice);
            }
        }

        $summaryLine = [] === $slice
            ? ''
            : \sprintf('Showing last %d of %d events (sanitized summaries only).', \count($slice), $eventCount)."\n";

        $lines = [rtrim($this->renderTemplate(self::TEMPLATE_EVENTS_HEADER, $this->identityVars($entry) + [
            'summary_line' => $summaryLine,
        ]))];

        foreach ($slice as $line) {
            $lines[] = $line;
        }

        if ([] === $slice) {
            $lines[] = '_(No events recorded.)_';
        }

        return implode("\n", $lines);
    }

    private function renderHistory(AgentArtifactEntryDTO $entry, int $limit, ?RunPresentationDTO $state): string
    {
        $slice = $state->historyLines ?? [];
        $summaryLine = [] === $slice
            ? ''
            : \sprintf('Showing last %d of %d eligible messages (system, user-context, and tool results omitted).', \count($slice), $state->eligibleMessageCount)."\n";
        $lines = [rtrim($this->renderTemplate(self::TEMPLATE_HISTORY_HEADER, $this->identityVars($entry) + [
            'summary_line' => $summaryLine,
        ]))];

        foreach ($slice as $line) {
            $lines[] = $line;
        }

        if ([] === $slice) {
            $lines[] = '_(No eligible messages in child state.)_';
        }

        return implode("\n", $lines);
    }

    private function renderHandoffHistory(AgentArtifactEntryDTO $entry, ?string $handoffId): string
    {
        $header = rtrim($this->renderTemplate(self::TEMPLATE_HANDOFF_HISTORY_HEADER, $this->identityVars($entry)));

        if (null !== $handoffId) {
            try {
                $body = $this->artifactRegistry->readHandoffHistoryEntry($entry->parentRunId, $entry->artifactId, $handoffId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false);
            }

            return $header."\n\n## Handoff {$handoffId}\n\n".$body;
        }

        $entries = $this->artifactRegistry->listHandoffHistory($entry->parentRunId, $entry->artifactId);
        $lines = [$header, '', 'Handoffs (oldest → newest). Pass handoff_id=<uuid> to fetch one body. Latest remains mode=handoff.'];

        if ([] === $entries) {
            $lines[] = '_(No handoffs.)_';

            return implode("\n", $lines);
        }

        foreach ($entries as $row) {
            $status = $row['status'] ?? 'unknown';
            $summary = $row['summary'] ?? '';
            $created = $row['created_at'] ?? '';
            $summaryPart = '' !== trim((string) $summary) ? ' — '.$this->truncateLine((string) $summary, 160) : '';
            $lines[] = \sprintf(
                '- id=%s created_at=%s status=%s%s',
                $row['id'],
                $created,
                $status,
                $summaryPart,
            );
        }

        return implode("\n", $lines);
    }

    private function renderDebug(AgentArtifactEntryDTO $entry): string
    {
        $p = $entry->paths;

        return $this->renderTemplate(self::TEMPLATE_DEBUG, $this->identityVars($entry) + [
            'status' => $entry->status->value,
            'artifact_dir' => $p->artifactDir,
            'metadata_path' => $p->metadataPath,
            'events_path' => $p->eventsPath,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function identityVars(AgentArtifactEntryDTO $entry): array
    {
        return [
            'artifact_id' => $entry->artifactId,
            'agent_run_id' => $entry->agentRunId,
            'agent_name' => $entry->agentName,
            'parent_run_id' => $entry->parentRunId,
        ];
    }

    /**
     * @param array<string, string> $vars
     */
    private function renderTemplate(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return strtr($template, $replacements);
    }

    private function loadChildPresentation(AgentArtifactEntryDTO $entry, int $limit): ?RunPresentationDTO
    {
        try {
            return $this->presentationReader->read($entry->agentRunId, $limit, self::HISTORY_SUMMARY_CHARS);
        } catch (\Throwable $e) {
            $this->logger->debug('agent_retrieve.child_state_unavailable', [
                'component' => 'agent.retrieve',
                'parent_run_id' => $entry->parentRunId,
                'agent_run_id' => $entry->agentRunId,
                'artifact_id' => $entry->artifactId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function summarizeEvent(RunEvent $event): string
    {
        $payload = $event->payload;

        return match ($event->type) {
            RunEventTypeEnum::ToolExecutionStart->value => $this->summarizeToolStart($payload),
            RunEventTypeEnum::ToolExecutionEnd->value => $this->summarizeToolEnd($payload),
            RunEventTypeEnum::ToolExecutionUpdate->value => 'tool progress update (payload omitted)',
            RunEventTypeEnum::LlmStepCompleted->value => $this->summarizeLlmCompleted($payload),
            RunEventTypeEnum::LlmStepFailed->value => 'llm step failed (details omitted)',
            RunEventTypeEnum::WaitingHuman->value => 'waiting for human input (unsupported for child runs)',
            RunEventTypeEnum::RunStarted->value => 'run started',
            RunEventTypeEnum::AgentEnd->value => 'agent ended',
            default => 'event (payload omitted)',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeToolStart(array $payload): string
    {
        $name = $payload['tool_name'] ?? $payload['toolName'] ?? 'unknown';

        return \is_string($name)
            ? 'tool start: '.$name
            : 'tool start';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeToolEnd(array $payload): string
    {
        $typedResult = $this->toolExecutionEndPayloadCodec->fromEventPayload($payload);
        $name = $typedResult->result['tool_name'] ?? 'unknown';
        $exit = $payload['exit_code'] ?? $payload['exitCode'] ?? null;
        $base = \is_string($name) ? 'tool end: '.$name : 'tool end';
        if (\is_int($exit)) {
            return $base.' exit='.$exit;
        }

        return $base.' (output omitted)';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeLlmCompleted(array $payload): string
    {
        $inner = $payload['payload'] ?? $payload;
        if (!\is_array($inner)) {
            return 'llm step completed';
        }

        $toolCalls = $inner['tool_calls'] ?? $inner['toolCalls'] ?? [];
        $count = \is_array($toolCalls) ? \count($toolCalls) : 0;

        return \sprintf('llm step completed (tool_calls=%d, text omitted)', $count);
    }

    private function truncateLine(string $text, int $max): string
    {
        $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $max - 1).'…';
    }
}
