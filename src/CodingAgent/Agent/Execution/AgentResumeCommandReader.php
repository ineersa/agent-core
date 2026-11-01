<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;

final readonly class AgentResumeCommandReader
{
    public function __construct(private EventStoreInterface $events)
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
        foreach ($this->events->reverseFor($childRunId) as $event) {
            if (($event->payload['idempotency_key'] ?? null) === $commandKey
                && ($event->payload['kind'] ?? null) === CoreCommandKind::FollowUp
                && \in_array($event->type, [RunEventTypeEnum::AgentCommandQueued->value, RunEventTypeEnum::AgentCommandApplied->value, RunEventTypeEnum::AgentCommandRejected->value], true)) {
                return $event;
            }
        }

        return null;
    }
}
