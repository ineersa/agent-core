<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds the post-commit AdvanceRun dispatch callback shared by pipeline
 * handlers.
 *
 * Every follow-up flow dispatches AdvanceRun with the same mechanics: a
 * fresh hrtime-based step id evaluated at invocation time (never at callback
 * creation), the committed predecessor turn, attempt 1, a SHA-256 "$runId|$stepId" idempotency key,
 * and Messenger failures wrapped into a flow-specific RuntimeException with
 * the original exception as previous. Only the step-id prefix and the failure
 * message vary per call site.
 */
final class AdvanceRunCallbackFactory
{
    public static function create(MessageBusInterface $commandBus, string $runId, int $turnNo, string $prefix, string $errorMessage): callable
    {
        return static function () use ($commandBus, $runId, $turnNo, $prefix, $errorMessage): void {
            $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

            try {
                $commandBus->dispatch(new AdvanceRun(
                    runId: $runId,
                    turnNo: $turnNo,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException($errorMessage, previous: $exception);
            }
        };
    }
}
