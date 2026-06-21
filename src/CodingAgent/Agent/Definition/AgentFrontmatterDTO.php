<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Normalized frontmatter DTO — the raw YAML fields after denormalization
 * and validation by Symfony Serializer + Validator.
 *
 * This DTO represents the user-supplied YAML frontmatter only.  It does NOT
 * include parser-added metadata (instructions, sourcePath, sourceDirectory).
 *
 * All validation constraints are declared as PHP attributes so Symfony
 * Validator can enforce them without manual is_* checks.
 *
 * @internal
 */
final class AgentFrontmatterDTO
{
    public function __construct(
        // --- Required fields ---

        #[Assert\NotBlank(message: '"name" is required.')]
        #[Assert\Regex(
            pattern: '/^[a-z][a-z0-9-]{0,47}$/',
            message: '"name" must be lowercase alphanumeric with hyphens (e.g. "my-agent").',
        )]
        public readonly string $name,

        #[Assert\NotBlank(normalizer: 'trim', message: '"description" is required and must not be empty.')]
        public readonly string $description,

        #[Assert\Count(min: 1, minMessage: '"tools" must be a non-empty list of strings.')]
        #[Assert\All([
            new Assert\Type('string', '"tools[{{ index }}]" must be a string.'),
            new Assert\NotBlank(
                normalizer: 'trim',
                message: '"tools[{{ index }}]" must not be empty.',
            ),
        ])]
        public readonly array $tools,

        // --- Optional fields with defaults ---

        public readonly ?string $model = null,

        #[Assert\Choice(
            choices: ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'],
            message: '"thinking" must be one of off|minimal|low|medium|high|xhigh.',
        )]
        public readonly ?string $thinking = null,

        #[Assert\All([
            new Assert\Type('string', '"skills[{{ index }}]" must be a string.'),
            new Assert\NotBlank(
                normalizer: 'trim',
                message: '"skills[{{ index }}]" must not be empty.',
            ),
        ])]
        public readonly array $skills = [],

        #[Assert\Type('bool', '"inheritProjectContext" must be a boolean.')]
        public readonly bool $inheritProjectContext = true,

        #[Assert\Type('bool', '"inheritAgentsMd" must be a boolean.')]
        public readonly bool $inheritAgentsMd = true,

        #[Assert\Choice(
            choices: ['replace', 'append'],
            message: '"systemPromptMode" must be one of replace|append.',
        )]
        public readonly string $systemPromptMode = 'replace',

        #[Assert\Range(
            notInRangeMessage: '"maxDepth" must be between 0 and 5.',
            min: 0,
            max: 5,
        )]
        public readonly int $maxDepth = 1,

        #[Assert\Type('bool', '"backgroundAllowed" must be a boolean.')]
        public readonly bool $backgroundAllowed = true,

        #[Assert\Type('bool', '"foregroundAllowed" must be a boolean.')]
        public readonly bool $foregroundAllowed = true,

        #[Assert\Type('bool', '"parallelAllowed" must be a boolean.')]
        public readonly bool $parallelAllowed = false,

        #[Assert\Type('bool', '"disabled" must be a boolean.')]
        public readonly bool $disabled = false,

        public readonly ?string $handoffFormat = null,

        #[Assert\Valid]
        public readonly ?McpFrontmatterDTO $mcp = null,
    ) {
    }
}
