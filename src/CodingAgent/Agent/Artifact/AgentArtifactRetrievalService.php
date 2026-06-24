<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Config\AgentArtifactRetrievalLimitsConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves parent-scoped subagent artifacts and renders bounded, privacy-safe
 * retrieval output for the {@see \Ineersa\CodingAgent\Agent\Tool\AgentRetrieveTool}.
 */
final class AgentArtifactRetrievalService
{
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

    private const string TEMPLATE_DEBUG = <<<'MD'
# Subagent artifact debug paths

- artifact_id: {artifact_id}
- agent_run_id: {agent_run_id}
- agent_name: {agent_name}
- parent_run_id: {parent_run_id}

- status: {status}
- artifact_dir: {artifact_dir}
- metadata_path: {metadata_path}
- handoff_path: {handoff_path}
- events_path: {events_path}
- state_path: {state_path}
MD;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly AgentRetrieveArgumentsFactory $argumentsFactory,
        private readonly AgentArtifactRetrievalLimitsConfig $limits,
        private readonly RunStoreInterface $runStore,
        private readonly EventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments tool arguments (artifact_id, agent_run_id, mode, limit)
     */
    public function retrieve(string $parentRunId, array $arguments): string
    {
        if ('' === trim($parentRunId)) {
            throw new ToolCallException('agent_retrieve requires an active parent run context.', retryable: false);
        }

        $args = $this->argumentsFactory->fromToolArguments($arguments);

        try {
            $mode = $args->resolvedMode();
            $limit = $args->resolvedLimit($this->limits->defaultLimit, $this->limits->maxLimit);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }

        $entry = $this->resolveEntry(
            $parentRunId,
            $args->trimmedArtifactId(),
            $args->trimmedAgentRunId(),
        );

        return match ($mode) {
            AgentRetrieveModeEnum::Handoff => $this->renderHandoff($entry),
            AgentRetrieveModeEnum::Metadata => $this->renderMetadata($entry),
            AgentRetrieveModeEnum::Events => $this->renderEvents($entry, $limit),
            AgentRetrieveModeEnum::History => $this->renderHistory($entry, $limit),
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

    private function renderMetadata(AgentArtifactEntryDTO $entry): string
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

        $state = $this->loadChildState($entry);
        if (null !== $state) {
            $vars['child_state_section'] = implode("\n", [
                '',
                '## Child run state',
                '- run_status: '.$state->status->value,
                '- turn_no: '.\sprintf('%d', $state->turnNo),
                '- last_seq: '.\sprintf('%d', $state->lastSeq),
                '- message_count: '.\sprintf('%d', \count($state->messages)),
                '- pending_tool_calls: '.\sprintf('%d', \count($state->pendingToolCalls)),
            ])."\n";
        }

        $events = $this->eventStore->allFor($entry->agentRunId);
        $vars['event_log_section'] = implode("\n", [
            '',
            '## Event log',
            '- event_count: '.\sprintf('%d', \count($events)),
        ]);

        return rtrim($this->renderTemplate(self::TEMPLATE_METADATA, $vars));
    }

    private function renderEvents(AgentArtifactEntryDTO $entry, int $limit): string
    {
        $events = $this->eventStore->allFor($entry->agentRunId);
        usort($events, static fn (RunEvent $a, RunEvent $b): int => $a->seq <=> $b->seq);
        $slice = \array_slice($events, -$limit);

        $summaryLine = [] === $slice
            ? ''
            : \sprintf('Showing last %d of %d events (sanitized summaries only).', \count($slice), \count($events))."\n";

        $lines = [rtrim($this->renderTemplate(self::TEMPLATE_EVENTS_HEADER, $this->identityVars($entry) + [
            'summary_line' => $summaryLine,
        ]))];

        foreach ($slice as $event) {
            $lines[] = \sprintf(
                '- seq=%d turn=%d type=%s at=%s — %s',
                $event->seq,
                $event->turnNo,
                $event->type,
                $event->createdAt->format(\DateTimeInterface::ATOM),
                $this->summarizeEvent($event),
            );
        }

        if ([] === $slice) {
            $lines[] = '_(No events recorded.)_';
        }

        return implode("\n", $lines);
    }

    private function renderHistory(AgentArtifactEntryDTO $entry, int $limit): string
    {
        $state = $this->loadChildState($entry);
        $messages = null !== $state ? $state->messages : [];

        $filtered = [];
        foreach ($messages as $message) {
            if ($this->shouldSkipHistoryMessage($message)) {
                continue;
            }
            $filtered[] = $message;
        }

        $slice = \array_slice($filtered, -$limit);

        $summaryLine = [] === $slice
            ? ''
            : \sprintf('Showing last %d of %d eligible messages (system, user-context, and tool results omitted).', \count($slice), \count($filtered))."\n";

        $lines = [rtrim($this->renderTemplate(self::TEMPLATE_HISTORY_HEADER, $this->identityVars($entry) + [
            'summary_line' => $summaryLine,
        ]))];

        foreach ($slice as $message) {
            $summary = $this->summarizeMessage($message);
            $tool = '';
            if (null !== $message->toolName && '' !== $message->toolName) {
                $tool = ' tool='.$message->toolName;
            }
            if (null !== $message->toolCallId && '' !== $message->toolCallId) {
                $tool .= ' tool_call_id='.$message->toolCallId;
            }
            $err = $message->isError ? ' error=yes' : '';
            $lines[] = \sprintf('- role=%s%s%s — %s', $message->role, $tool, $err, $summary);
        }

        if ([] === $slice) {
            $lines[] = '_(No eligible messages in child state.)_';
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
            'handoff_path' => $p->handoffPath,
            'events_path' => $p->eventsPath,
            'state_path' => $p->statePath,
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

    private function loadChildState(AgentArtifactEntryDTO $entry): ?RunState
    {
        try {
            return $this->runStore->get($entry->agentRunId);
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

    private function shouldSkipHistoryMessage(AgentMessage $message): bool
    {
        return \in_array($message->role, ['system', 'user-context', 'tool'], true);
    }

    private function summarizeMessage(AgentMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? null;
            if ('text' === $type && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        $text = trim(implode(' ', $parts));

        return $this->truncateLine('' === $text ? '(non-text content omitted)' : $text, $this->limits->historySummaryChars);
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
        $name = $payload['tool_name'] ?? $payload['toolName'] ?? 'unknown';
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
