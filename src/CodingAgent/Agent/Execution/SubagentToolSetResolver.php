<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorates the normal ToolSetResolver chain with per-run tool policy
 * filtering for child agent runs.
 *
 * When a runId resolves to a child agent run (identified by RunStarted
 * metadata.session.kind === 'agent_child'), this resolver:
 *  1. Reads the RunStarted event via SubagentRunMetadataReader.
 *  2. Extracts the resolved tool policy from
 *     metadata.tools_scope.allowed_tools.
 *  3. Intersects the inner resolver's ActiveToolSet with the child's
 *     allowed tools — both toolNames and allowListNames.
 *  4. Filters executionModes to only include entries for tools that
 *     remain after intersection.
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
        private SubagentRunMetadataReader $metadataReader,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        $inner = $this->inner->resolve($toolsRef, $turnNo, $runId);

        if (null === $runId || '' === $runId) {
            return $inner;
        }

        $allowedTools = $this->metadataReader->readAllowedTools($runId);
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
        $allowedLookup = array_flip($allowedTools);

        $filteredToolNames = array_values(
            array_intersect($inner->toolNames, $allowedTools),
        );

        $filteredAllowList = array_values(
            array_intersect($inner->allowListNames, $allowedTools),
        );

        // Filter executionModes to only include tools that remain after
        // intersection — not stale modes for removed tools.
        $filteredExecutionModes = [];
        foreach ($inner->executionModes as $toolName => $mode) {
            if (isset($allowedLookup[$toolName])) {
                $filteredExecutionModes[$toolName] = $mode;
            }
        }

        $filteredTimeoutSeconds = [];
        foreach ($inner->timeoutSeconds as $toolName => $seconds) {
            if (isset($allowedLookup[$toolName])) {
                $filteredTimeoutSeconds[$toolName] = $seconds;
            }
        }

        return new ActiveToolSet(
            toolNames: $filteredToolNames,
            allowListNames: $filteredAllowList,
            executionModes: $filteredExecutionModes,
            timeoutSeconds: $filteredTimeoutSeconds,
        );
    }
}
