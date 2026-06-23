<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Psr\Log\LoggerInterface;

/**
 * Decorates the normal ToolSetResolver chain with per-run tool policy
 * filtering for child agent runs.
 *
 * When a runId resolves to a child agent run (identified by RunStarted
 * metadata.kind === 'agent_child'), this resolver:
 *  1. Reads the RunStarted event from the EventStoreInterface.
 *  2. Extracts the resolved tool policy from tools_scope.allowed_tools.
 *  3. Intersects the inner resolver's ActiveToolSet with the child's
 *     allowed tools — both toolNames and allowListNames.
 *
 * For parent (non-child) runs or when child metadata is missing,
 * passes through to the inner resolver unchanged.
 *
 * This approach avoids mutating the global ToolRegistry and naturally
 * supports concurrent runs with different policies.
 */
final readonly class SubagentToolSetResolver implements ToolSetResolverInterface
{
    public function __construct(
        private ToolSetResolverInterface $inner,
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        $inner = $this->inner->resolve($toolsRef, $turnNo, $runId);

        if (null === $runId || '' === $runId) {
            return $inner;
        }

        $allowedTools = $this->readChildPolicy($runId);
        if (null === $allowedTools) {
            // Not a child run or policy not available — pass through.
            $this->logger->debug('subagent_resolver.passthrough', [
                'component' => 'agent.resolver',
                'event_type' => 'subagent_resolver.passthrough',
                'run_id' => $runId,
            ]);

            return $inner;
        }

        // Intersect the inner toolset with the child's allowed tools.
        $filteredToolNames = array_values(
            array_intersect($inner->toolNames, $allowedTools),
        );

        $filteredAllowList = array_values(
            array_intersect($inner->allowListNames, $allowedTools),
        );

        return new ActiveToolSet(
            toolNames: $filteredToolNames,
            allowListNames: $filteredAllowList,
            executionModes: $inner->executionModes,
        );
    }

    /**
     * Read the child agent's allowed tool policy from RunStarted metadata.
     *
     * Returns the allowed tool names or null when:
     *  - The run is not a child agent run.
     *  - The RunStarted event is not yet available.
     *
     * @return list<string>|null
     */
    private function readChildPolicy(string $runId): ?array
    {
        $events = $this->eventStore->allFor($runId);

        foreach ($events as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            $kind = $event->payload['kind'] ?? null;
            if ('agent_child' !== $kind) {
                // Not a child agent run.
                return null;
            }

            $toolsScope = $event->payload['tools_scope'] ?? [];
            if (!\is_array($toolsScope)) {
                return null;
            }

            $tools = $toolsScope['allowed_tools'] ?? null;
            if (!\is_array($tools)) {
                return null;
            }

            /* @var list<string> */
            return $tools;
        }

        return null;
    }
}
