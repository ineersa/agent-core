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
     * @param list<string>|null $tools      null means inherit all parent-available tools at child launch
     * @param list<string>      $skills
     * @param list<string>|null $extensions null means no optional child extensions (always_on only)
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?array $tools,
        public ?string $model = null,
        public ?string $thinking = null,
        public array $skills = [],
        public ?array $extensions = null,
        public bool $inheritProjectContext = true,
        public SystemPromptModeEnum $systemPromptMode = SystemPromptModeEnum::Replace,
        public bool $parallelAllowed = true,
        public string $instructions = '',
        public string $sourcePath = '',
    ) {
    }
}
