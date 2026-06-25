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
     * @param list<string> $tools
     * @param list<string> $skills
     */
    public function __construct(
        public string $name,
        public string $description,
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
        public bool $parallelAllowed = true,
        public bool $disabled = false,
        public ?string $handoffFormat = null,
        public string $instructions = '',
        public string $sourcePath = '',
        public string $sourceDirectory = '',
    ) {
    }
}
