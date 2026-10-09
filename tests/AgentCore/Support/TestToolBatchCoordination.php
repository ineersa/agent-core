<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollectOutcome;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Symfony\Component\Uid\Uuid;

/** Algorithm tests explicitly finalize decisions through the store contract. */
final class TestToolBatchCoordination
{
    public static function finalize(
        ToolBatchStoreInterface $store,
        ?FinalizeToolBatchDTO $action,
    ): void {
        if (null === $action) {
            return;
        }
        $transition = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $action->runId, 'actions' => [$action]]);
        $store->prepareChanges([$action], $transition)();
    }

    public static function collect(ToolBatchCollector $collector, ToolBatchStoreInterface $store, ToolCallResult $result): ToolBatchCollectOutcome
    {
        $outcome = $collector->prepareCollect($result);
        self::finalize($store, $outcome->action);

        return $outcome;
    }

    /** @return list<ExecuteToolCall> */
    public static function suspend(ToolBatchCollector $collector, ToolBatchStoreInterface $store, string $runId, int $turnNo, string $stepId, string $toolCallId, string $questionId): array
    {
        $prepared = $collector->prepareHumanInputSuspension($runId, $turnNo, $stepId, $toolCallId, $questionId);
        self::finalize($store, $prepared->action);

        return $prepared->effects;
    }

    /** @return list<ExecuteToolCall> */
    public static function resume(ToolBatchCollector $collector, ToolBatchStoreInterface $store, string $runId, int $turnNo, string $stepId, string $toolCallId, string $questionId, ToolCallHumanInputAnswerDTO $answer): array
    {
        $prepared = $collector->prepareHumanInputAnswer($runId, $turnNo, $stepId, $toolCallId, $questionId, $answer);
        self::finalize($store, $prepared->action);

        return $prepared->effects;
    }

    /** @return list<ExecuteToolCall> */
    public static function redrive(ToolBatchCollector $collector, ToolBatchStoreInterface $store, string $runId, int $turnNo, string $stepId, string $questionId, mixed $answerValue): array
    {
        $prepared = $collector->prepareHumanInputRedrive($runId, $turnNo, $stepId, $questionId, $answerValue);
        self::finalize($store, $prepared->action);

        return $prepared->effects;
    }
}
