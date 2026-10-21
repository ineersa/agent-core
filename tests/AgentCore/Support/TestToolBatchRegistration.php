<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Symfony\Component\Uid\Uuid;

/** Apply producer-captured registration through the real store contract. */
final class TestToolBatchRegistration
{
    /**
     * @param list<ExecuteToolCall> $toolCalls
     *
     * @return list<ExecuteToolCall>
     */
    public static function register(
        ToolBatchCollector $collector,
        ToolBatchStoreInterface $store,
        string $runId,
        int $turnNo,
        string $stepId,
        array $toolCalls,
    ): array {
        $existing = $store->load($runId, $turnNo, $stepId);
        $action = $collector->prepareRegistration($runId, $turnNo, $stepId, $toolCalls);
        self::apply($store, $action);
        if (null !== $existing) {
            return [];
        }

        return array_values(array_filter($toolCalls, static fn (ExecuteToolCall $call): bool => isset($action->inFlight[$call->toolCallId])));
    }

    public static function registerDeferredInvocation(ToolBatchStoreInterface $store, DeferredToolCompletionCorrelation $correlation): void
    {
        self::registerDeferredInvocations($store, $correlation);
    }

    public static function registerDeferredInvocations(ToolBatchStoreInterface $store, DeferredToolCompletionCorrelation $correlation, DeferredToolCompletionCorrelation ...$siblings): void
    {
        $calls = array_map(static fn (DeferredToolCompletionCorrelation $correlation): ExecuteToolCall => new ExecuteToolCall(
            $correlation->runId, $correlation->turnNo, $correlation->stepId,
            $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId,
            $correlation->toolName, $correlation->arguments, $correlation->orderIndex,
            toolIdempotencyKey: $correlation->toolIdempotencyKey,
            mode: $correlation->mode,
            timeoutSeconds: $correlation->timeoutSeconds,
            maxParallelism: $correlation->maxParallelism,
            argSchema: $correlation->argSchema,
            toolsRef: $correlation->toolsRef,
        ), [$correlation, ...$siblings]);
        self::register(new ToolBatchCollector($store), $store, $correlation->runId, $correlation->turnNo, $correlation->stepId, $calls);
    }

    public static function apply(
        ToolBatchStoreInterface $store,
        RegisterToolBatchDTO $action,
    ): void {
        $transition = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $action->runId, 'actions' => [$action]]);
        $store->prepareChanges([$action], $transition)();
    }
}
