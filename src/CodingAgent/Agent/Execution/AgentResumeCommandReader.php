<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;

final readonly class AgentResumeCommandReader
{
    public function __construct(private EventStoreInterface $events, private RunHistorySourceProviderInterface $sources)
    {
    }

    public function isResumeCommand(ApplyCommand $command): bool
    {
        if (CoreCommandKind::FollowUp !== $command->kind) {
            return false;
        }
        $message = $command->payload['message'] ?? null;
        $metadata = \is_array($message) ? ($message['metadata'] ?? null) : null;
        $task = \is_array($metadata) ? ($metadata['agent_task'] ?? null) : null;
        $artifact = \is_array($task) ? ($task['artifact_id'] ?? null) : null;

        return \is_string($artifact) && '' !== trim($artifact);
    }

    public function find(string $childRunId, string $commandKey): ?RunEvent
    {
        $source = $this->sources->historySource($childRunId);
        if (!is_file($source->path)) {
            return null;
        }
        $cut = $source->log->historyCut($source->path, $childRunId);
        $sequence = $source->log->latestCommandSequences($source->path, $childRunId, [$commandKey], $cut['sequence'] ?? 0)[$commandKey] ?? null;
        if (null === $sequence) {
            return null;
        }
        $indexed = null;
        foreach ($this->events->rangeFor($childRunId, $sequence, $sequence) as $event) {
            if (null !== $indexed || $event->seq !== $sequence || $event->runId !== $childRunId
                || ($event->payload['idempotency_key'] ?? null) !== $commandKey) {
                throw new \RuntimeException('Indexed resume command evidence does not match canonical history.');
            }
            $indexed = $event;
        }
        unset($event);
        if (null === $indexed) {
            throw new \RuntimeException('Indexed resume command evidence is missing from canonical history.');
        }
        if ($this->matches($indexed, $commandKey)) {
            return $indexed;
        }

        // The index is identity-only: a reused key's newer kind/status must
        // not hide older qualifying follow-up evidence.
        foreach ($this->events->reverseFor($childRunId) as $event) {
            if ($event->seq < $sequence && $this->matches($event, $commandKey)) {
                return $event;
            }
        }

        return null;
    }

    private function matches(RunEvent $event, string $commandKey): bool
    {
        return ($event->payload['idempotency_key'] ?? null) === $commandKey
            && ($event->payload['kind'] ?? null) === CoreCommandKind::FollowUp
            && \in_array($event->type, [RunEventTypeEnum::AgentCommandQueued->value, RunEventTypeEnum::AgentCommandApplied->value, RunEventTypeEnum::AgentCommandRejected->value], true);
    }
}
