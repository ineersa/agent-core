<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
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
        ?ExecutionOperationStoreInterface $operations = null,
    ): array {
        $existing = $store->load($runId, $turnNo, $stepId);
        $action = $collector->prepareRegistration($runId, $turnNo, $stepId, $toolCalls);
        self::apply($store, $action, $operations);
        if (null !== $existing) {
            return [];
        }

        return $store->admittedCalls($runId, $turnNo, $stepId);
    }

    public static function apply(
        ToolBatchStoreInterface $store,
        RegisterToolBatchDTO $action,
        ?ExecutionOperationStoreInterface $operations = null,
    ): void {
        $transition = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), 0, ['run_id' => $action->runId, 'actions' => [$action]]);
        if (null !== $operations) {
            foreach ($action->effects as $effect) {
                $operations->prepare($effect, $transition);
            }
        }
        $store->registerPrepared($action, $transition);
    }
}
