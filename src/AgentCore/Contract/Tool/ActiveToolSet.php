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
 */
final readonly class ActiveToolSet
{
    /**
     * @param list<string> $toolNames      Provider-visible tool names (schemas sent to LLM)
     * @param list<string> $allowListNames Execution allowlist names (accepted for execution)
     */
    public function __construct(
        public array $toolNames = [],
        public array $allowListNames = [],
    ) {
    }
}
