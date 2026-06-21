<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Typed agent definition DTO.
 *
 * Immutable value object built by {@see AgentDefinitionParser} from a single
 * Markdown agent definition file with YAML frontmatter.  All optional fields
 * carry sensible defaults defined by the parser/validator.
 *
 * The body/instructions are stored in the {@see $instructions} property and
 * represent the Markdown content after the closing YAML delimiter.
 */
final readonly class AgentDefinitionDTO
{
    /**
     * @param string               $name                  Unique agent name (lowercase alphanumeric with hyphens)
     * @param string               $description           Human-readable description
     * @param AgentTypeEnum        $type                  Agent type classification
     * @param string|null          $model                 Optional model override (null = inherit parent model)
     * @param string|null          $thinking              Optional reasoning/thinking level override
     * @param list<string>         $tools                 Explicit tool allowlist
     * @param McpPolicyDTO         $mcp                   MCP access policy
     * @param list<string>         $skills                Setup skills loaded from start
     * @param bool                 $inheritProjectContext Whether to include project AGENTS.md context
     * @param bool                 $inheritAgentsMd       Whether to include AGENTS.md instructions
     * @param SystemPromptModeEnum $systemPromptMode      How the system prompt interacts with parent
     * @param int                  $maxDepth              Per-agent recursion depth cap (0-5)
     * @param bool                 $backgroundAllowed     Whether background launches are allowed
     * @param bool                 $foregroundAllowed     Whether foreground launches are allowed
     * @param bool                 $parallelAllowed       Whether the agent can be launched in parallel
     * @param bool                 $disabled              Disable this definition without deleting the file
     * @param string|null          $handoffFormat         Optional named handoff template override
     * @param string               $instructions          Body prompt / instructions (Markdown after frontmatter)
     * @param string               $sourcePath            Absolute path to the definition file
     * @param string               $sourceDirectory       Absolute path to the directory containing the file
     */
    public function __construct(
        public string $name,
        public string $description,
        public AgentTypeEnum $type,
        public array $tools,
        public McpPolicyDTO $mcp,
        public ?string $model = null,
        public ?string $thinking = null,
        public array $skills = [],
        public bool $inheritProjectContext = true,
        public bool $inheritAgentsMd = true,
        public SystemPromptModeEnum $systemPromptMode = SystemPromptModeEnum::Replace,
        public int $maxDepth = 1,
        public bool $backgroundAllowed = true,
        public bool $foregroundAllowed = true,
        public bool $parallelAllowed = false,
        public bool $disabled = false,
        public ?string $handoffFormat = null,
        public string $instructions = '',
        public string $sourcePath = '',
        public string $sourceDirectory = '',
    ) {
    }
}
