<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RejectCommandDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/** Captures mailbox decisions from the prepared events, without changing the store. */
final class CommandMailboxCoordinationFactory
{
    public static function isMailboxAction(object $action): bool
    {
        return $action instanceof \Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO || $action instanceof MarkCommandAppliedDTO || $action instanceof RejectCommandDTO;
    }

    public static function finalize(HandlerResult $result): HandlerResult
    {
        $actions = [];
        foreach ($result->events as $event) {
            $key = $event->payload['idempotency_key'] ?? null;
            if (!\is_string($key)) {
                continue;
            }
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
                if (!array_any($result->postCommitActions, static fn (object $action): bool => $action instanceof MarkCommandAppliedDTO && $action->runId === $event->runId && $action->idempotencyKey === $key)) {
                    $actions[] = new MarkCommandAppliedDTO($event->runId, $key);
                }
            } elseif (RunEventTypeEnum::AgentCommandRejected->value === $event->type) {
                $reason = $event->payload['reason'] ?? null;
                if (!\is_string($reason)) {
                    throw new \LogicException('Command rejection lacks its prepared reason.');
                }
                $actions[] = new RejectCommandDTO($event->runId, $key, $reason);
            }
        }

        return new HandlerResult($result->nextState, $result->events, $result->effects, $result->postCommitEffects, [...$actions, ...$result->postCommitActions]);
    }
}
