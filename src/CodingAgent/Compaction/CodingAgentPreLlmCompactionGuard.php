<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * CodingAgent implementation of the pre-LLM compaction guard.
 *
 * Resolves per-provider/per-model runtime settings, estimates tokens,
 * and answers whether compaction should run before the next LLM call.
 *
 * Registered in the container so {@see \Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler}
 * can inject the AgentCore contract without depending on CodingAgent.
 */
final readonly class CodingAgentPreLlmCompactionGuard implements PreLlmCompactionGuardInterface
{
    public function __construct(
        private CompactionConfig $compactionConfig,
        private CompactionTokenEstimator $tokenEstimator,
        private ActiveModelResolverInterface $modelResolver,
    ) {
    }

    public function shouldCompactBeforeLlmStep(
        string $runId,
        int $nextTurnNo,
        array $messages,
        ?string $activeStepId,
    ): bool {
        unset($nextTurnNo);

        // Resolve per-provider/per-model runtime settings.
        $activeModel = $this->modelResolver->getActiveModel($runId);
        $runtimeSettings = $this->compactionConfig->resolveRuntimeSettings($activeModel);

        if (!$runtimeSettings->autoEnabled) {
            return false;
        }

        // Do not compact when another compaction is already in flight.
        if (null !== $activeStepId && str_starts_with($activeStepId, 'compact-')) {
            return false;
        }

        // Check the flat token threshold.
        $estimatedTokens = $this->tokenEstimator->estimateTokens($messages);

        return $estimatedTokens > $runtimeSettings->compactAfterTokens;
    }
}
