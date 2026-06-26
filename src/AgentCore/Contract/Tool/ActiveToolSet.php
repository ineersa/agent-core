<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Typed DTO for resolved active toolset data.
 *
 * Carries both the provider-visible tool names and the execution-allowlist
 * names derived from a single ToolRegistry snapshot. Consumers use this to
 * decide which tool schemas to expose to the LLM and which tool names to
 * accept for execution.
 *
 * executionModes maps tool name to its execution mode value string
 * timeoutSeconds maps tool name to per-tool timeout override (seconds); absent keys use global default.
 * (e.g. 'sequential', 'parallel', 'interrupt'), sourced from the
 * tool's ToolDefinitionDTO. LlmStepResultHandler reads this to set
 * the mode on ExecuteToolCall messages, which ToolBatchCollector
 * and ToolExecutor then respect.
 */
final readonly class ActiveToolSet
{
    /**
     * @param list<string>          $toolNames      Provider-visible tool names (schemas sent to LLM)
     * @param list<string>          $allowListNames Execution allowlist names (accepted for execution)
     * @param array<string, string> $executionModes Tool name => mode value map (e.g. 'sequential', 'parallel')
     * @param array<string, int>    $timeoutSeconds Tool name => timeout seconds override; omit for default
     */
    public function __construct(
        public array $toolNames = [],
        public array $allowListNames = [],
        public array $executionModes = [],
        public array $timeoutSeconds = [],
    ) {
    }
}
