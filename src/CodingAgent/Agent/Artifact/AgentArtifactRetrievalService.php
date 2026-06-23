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
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Resolves parent-scoped subagent artifacts and renders bounded, privacy-safe
 * retrieval output for the {@see \Ineersa\CodingAgent\Agent\Tool\AgentRetrieveTool}.
 */
final class AgentArtifactRetrievalService
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 100;
    private const int HISTORY_SUMMARY_CHARS = 240;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly RunStoreInterface $runStore,
        private readonly EventStoreInterface $eventStore,
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

        $artifactId = $this->optionalTrimmedString($arguments, 'artifact_id');
        $agentRunId = $this->optionalTrimmedString($arguments, 'agent_run_id');

        if (null === $artifactId && null === $agentRunId) {
            throw new ToolCallException('Provide at least one identifier: artifact_id or agent_run_id.', retryable: false, hint: 'Example: {"artifact_id": "agent_abc123", "mode": "handoff"} or {"agent_run_id": "<child-run-uuid>"}.');
        }

        $mode = $this->resolveMode($arguments);
        $limit = $this->resolveLimit($arguments);

        $entry = $this->resolveEntry($parentRunId, $artifactId, $agentRunId);

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
                $foreign = $this->findArtifactInOtherParents($artifactId, $parentRunId);
                if (null !== $foreign) {
                    throw new ToolCallException(\sprintf('Artifact "%s" belongs to a different parent session and cannot be retrieved from the current run.', $artifactId), retryable: false, hint: 'Retrieve only artifacts created under the current parent session.');
                }

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

    /**
     * @return ?array{parentRunId: string}
     */
    private function findArtifactInOtherParents(string $artifactId, string $currentParentRunId): ?array
    {
        $matches = [];

        foreach ($this->hatfieldSessionStore->listSessions() as $session) {
            $sessionId = $session['sessionId'] ?? null;
            if (!\is_string($sessionId) || '' === $sessionId || $sessionId === $currentParentRunId) {
                continue;
            }

            try {
                $entry = $this->artifactRegistry->get($sessionId, $artifactId);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (null !== $entry) {
                $matches[$sessionId] = true;
            }
        }

        if ([] === $matches) {
            return null;
        }

        if (\count($matches) > 1) {
            throw new ToolCallException(\sprintf('Artifact id "%s" is ambiguous across multiple parent sessions. Retrieval is limited to the current parent session.', $artifactId), retryable: false);
        }

        $foreignParent = array_key_first($matches);

        return ['parentRunId' => $foreignParent];
    }

    private function renderHandoff(AgentArtifactEntryDTO $entry): string
    {
        $handoff = $this->artifactRegistry->readHandoff($entry->parentRunId, $entry->artifactId);
        $header = $this->identityHeader($entry);

        if ('' === trim($handoff)) {
            return $header."\n\n_(No handoff content stored.)_";
        }

        return $header."\n\n".$handoff;
    }

    private function renderMetadata(AgentArtifactEntryDTO $entry): string
    {
        $lines = [
            '# Subagent artifact metadata',
            '',
            ...$this->identityLines($entry),
            '',
            '- status: '.$entry->status->value,
            '- created_at: '.$entry->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if (null !== $entry->startedAt) {
            $lines[] = '- started_at: '.$entry->startedAt->format(\DateTimeInterface::ATOM);
        }
        if (null !== $entry->completedAt) {
            $lines[] = '- completed_at: '.$entry->completedAt->format(\DateTimeInterface::ATOM);
        }
        if (null !== $entry->summary && '' !== trim($entry->summary)) {
            $lines[] = '- summary: '.$this->truncateLine($entry->summary, 500);
        }
        if (null !== $entry->failureReason && '' !== trim($entry->failureReason)) {
            $lines[] = '- failure_reason: '.$this->truncateLine($entry->failureReason, 500);
        }
        if (null !== $entry->needsClarification && '' !== trim($entry->needsClarification)) {
            $lines[] = '- needs_clarification: '.$this->truncateLine($entry->needsClarification, 500);
        }

        $state = $this->loadChildState($entry);
        if (null !== $state) {
            $lines[] = '';
            $lines[] = '## Child run state';
            $lines[] = '- run_status: '.$state->status->value;
            $lines[] = '- turn_no: '.\sprintf('%d', $state->turnNo);
            $lines[] = '- last_seq: '.\sprintf('%d', $state->lastSeq);
            $lines[] = '- message_count: '.\sprintf('%d', \count($state->messages));
            $lines[] = '- pending_tool_calls: '.\sprintf('%d', \count($state->pendingToolCalls));
        }

        $events = $this->eventStore->allFor($entry->agentRunId);
        $lines[] = '';
        $lines[] = '## Event log';
        $lines[] = '- event_count: '.\sprintf('%d', \count($events));

        return implode("\n", $lines);
    }

    private function renderEvents(AgentArtifactEntryDTO $entry, int $limit): string
    {
        $events = $this->eventStore->allFor($entry->agentRunId);
        usort($events, static fn (RunEvent $a, RunEvent $b): int => $a->seq <=> $b->seq);
        $slice = \array_slice($events, -$limit);

        $lines = [
            '# Subagent recent events',
            '',
            ...$this->identityLines($entry),
            '',
            \sprintf('Showing last %d of %d events (sanitized summaries only).', \count($slice), \count($events)),
            '',
        ];

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

        $lines = [
            '# Subagent message history (bounded)',
            '',
            ...$this->identityLines($entry),
            '',
            \sprintf('Showing last %d of %d eligible messages (system and user-context omitted).', \count($slice), \count($filtered)),
            '',
        ];

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

        return implode("\n", [
            '# Subagent artifact debug paths',
            '',
            ...$this->identityLines($entry),
            '',
            '- status: '.$entry->status->value,
            '- artifact_dir: '.$p->artifactDir,
            '- metadata_path: '.$p->metadataPath,
            '- handoff_path: '.$p->handoffPath,
            '- events_path: '.$p->eventsPath,
            '- state_path: '.$p->statePath,
        ]);
    }

    private function identityHeader(AgentArtifactEntryDTO $entry): string
    {
        return implode("\n", [
            '# Subagent handoff',
            '',
            ...$this->identityLines($entry),
            '- status: '.$entry->status->value,
        ]);
    }

    /**
     * @return list<string>
     */
    private function identityLines(AgentArtifactEntryDTO $entry): array
    {
        return [
            '- artifact_id: '.$entry->artifactId,
            '- agent_run_id: '.$entry->agentRunId,
            '- agent_name: '.$entry->agentName,
            '- parent_run_id: '.$entry->parentRunId,
        ];
    }

    private function loadChildState(AgentArtifactEntryDTO $entry): ?RunState
    {
        try {
            return $this->runStore->get($entry->agentRunId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldSkipHistoryMessage(AgentMessage $message): bool
    {
        if ('system' === $message->role) {
            return true;
        }

        if ('user-context' === $message->role) {
            return true;
        }

        return false;
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

        return $this->truncateLine('' === $text ? '(non-text content omitted)' : $text, self::HISTORY_SUMMARY_CHARS);
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

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveMode(array $arguments): AgentRetrieveModeEnum
    {
        $raw = $arguments['mode'] ?? 'handoff';
        if (!\is_string($raw) || '' === trim($raw)) {
            return AgentRetrieveModeEnum::Handoff;
        }

        $mode = AgentRetrieveModeEnum::tryFrom(trim($raw));
        if (null === $mode) {
            throw new ToolCallException(\sprintf('Invalid mode "%s". Supported modes: handoff, metadata, events, history, debug.', $raw), retryable: false);
        }

        return $mode;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveLimit(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }

        $limit = $arguments['limit'];
        if (!\is_int($limit) && !(\is_string($limit) && ctype_digit($limit))) {
            throw new ToolCallException('limit must be an integer between 1 and 100.', retryable: false);
        }

        $limit = (int) $limit;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolCallException('limit must be between 1 and 100.', retryable: false);
        }

        return $limit;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function optionalTrimmedString(array $arguments, string $key): ?string
    {
        if (!isset($arguments[$key])) {
            return null;
        }

        $value = $arguments[$key];
        if (!\is_string($value)) {
            throw new ToolCallException(\sprintf('"%s" must be a string when provided.', $key), retryable: false);
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        return $trimmed;
    }
}
