<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

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
 *  4. Drops tools owned by extensions outside metadata.extensions.
 *  5. Filters executionModes/timeoutSeconds/backgroundPromptAllowed to
 *     only include entries for tools that remain after intersection.
 *  6. Disables Bash background HITL for remaining child Bash tools.
 *
 * For parent (non-child) runs or when child metadata is missing,
 * passes through to the inner resolver unchanged.
 *
 * This approach avoids mutating the global ToolRegistry and naturally
 * supports concurrent runs with different policies.
 */
final readonly class SubagentToolSetResolver implements ToolSetResolverInterface
{
    private const string BASH_TOOL_NAME = 'bash';

    public function __construct(
        private ToolSetResolverInterface $inner,
        private SubagentRunMetadataReader $metadataReader,
        private ToolRegistryInterface $toolRegistry,
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

        // Drop tools owned by extensions not in the child's effective allowlist.
        // Built-in tools (no extension owner) stay; only extension-owned tools filter.
        $allowedExtensions = $this->metadataReader->readAllowedExtensions($runId);
        if (null !== $allowedExtensions) {
            $extensionAllowed = array_fill_keys($allowedExtensions, true);
            $filteredToolNames = array_values(array_filter(
                $filteredToolNames,
                function (string $name) use ($extensionAllowed): bool {
                    $definition = $this->toolRegistry->toolDefinition($name);
                    if (null === $definition) {
                        // Built-in/dynamic tools not currently visible, or unknown names:
                        // keep the prior tools_scope decision.
                        return true;
                    }
                    $owner = $definition->extensionOwnerClass;

                    return null === $owner || isset($extensionAllowed[$owner]);
                },
            ));
            $filteredAllowList = array_values(array_intersect($filteredAllowList, $filteredToolNames));
            $allowedLookup = array_flip($filteredToolNames);
        }

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

        $filteredBackgroundPromptAllowed = [];
        foreach ($inner->backgroundPromptAllowed as $toolName => $allowed) {
            if (isset($allowedLookup[$toolName])) {
                $filteredBackgroundPromptAllowed[$toolName] = $allowed;
            }
        }

        // Child runs never offer Bash background HITL; keep foreground
        // supervision so per-call Bash timeouts remain enforceable.
        if (isset($allowedLookup[self::BASH_TOOL_NAME])) {
            $filteredBackgroundPromptAllowed[self::BASH_TOOL_NAME] = false;
        }

        return new ActiveToolSet(
            toolNames: $filteredToolNames,
            allowListNames: $filteredAllowList,
            executionModes: $filteredExecutionModes,
            timeoutSeconds: $filteredTimeoutSeconds,
            backgroundPromptAllowed: $filteredBackgroundPromptAllowed,
        );
    }
}
