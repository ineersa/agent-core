<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollectOutcome;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;

/** Algorithm tests explicitly finalize decisions. Journal proofs use the configured store. */
final class TestToolBatchCoordination
{
    public static function finalize(ToolBatchCollector $collector, ?FinalizeToolBatchDTO $action): void
    {
        if (null !== $action) {
            $collector->finalizePreparedBatch($action, new VerifiedTransitionDTO('algorithm-test', 0, ['run_id' => $action->runId, 'actions' => [$action]]));
        }
    }

    public static function collect(ToolBatchCollector $collector, ToolCallResult $result): ToolBatchCollectOutcome
    {
        $outcome = $collector->prepareCollect($result);
        self::finalize($collector, $outcome->action);

        return $outcome;
    }

    /** @return list<\Ineersa\AgentCore\Domain\Message\ExecuteToolCall> */
    public static function suspend(ToolBatchCollector $collector, string $runId, int $turnNo, string $stepId, string $toolCallId, string $questionId): array
    {
        $prepared = $collector->prepareHumanInputSuspension($runId, $turnNo, $stepId, $toolCallId, $questionId);
        self::finalize($collector, $prepared->action);

        return $prepared->effects;
    }

    /** @return list<\Ineersa\AgentCore\Domain\Message\ExecuteToolCall> */
    public static function resume(ToolBatchCollector $collector, string $runId, int $turnNo, string $stepId, string $toolCallId, string $questionId, ToolCallHumanInputAnswerDTO $answer): array
    {
        $prepared = $collector->prepareHumanInputAnswer($runId, $turnNo, $stepId, $toolCallId, $questionId, $answer);
        self::finalize($collector, $prepared->action);

        return $prepared->effects;
    }

    /** @return list<\Ineersa\AgentCore\Domain\Message\ExecuteToolCall> */
    public static function redrive(ToolBatchCollector $collector, string $runId, int $turnNo, string $stepId, string $questionId, mixed $answerValue): array
    {
        $prepared = $collector->prepareHumanInputRedrive($runId, $turnNo, $stepId, $questionId, $answerValue);
        self::finalize($collector, $prepared->action);

        return $prepared->effects;
    }
}
