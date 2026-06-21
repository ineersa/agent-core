<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * MCP frontmatter sub-DTO — the "mcp" key in agent definition YAML.
 *
 * Nested object denormalized and validated by Symfony Serializer + Validator.
 *
 * @internal
 */
final class McpFrontmatterDTO
{
    public function __construct(
        #[Assert\Choice(
            choices: ['none', 'specific', 'all'],
            message: '"mcp.mode" must be one of none|specific|all.',
        )]
        public readonly ?string $mode = null,

        #[Assert\All([
            new Assert\Type('string', '"mcp.tools[{{ index }}]" must be a string.'),
            new Assert\NotBlank(message: '"mcp.tools[{{ index }}]" must not be empty.'),
            new Assert\Regex(
                pattern: '/^\\S+(\\s+\\S+)*$/',
                message: '"mcp.tools[{{ index }}]" must not have leading or trailing whitespace.',
            ),
        ])]
        public readonly array $tools = [],
    ) {
    }

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        if (!array_is_list($this->tools)) {
            $context->buildViolation('Must be a list (sequential array), got associative array.')
                ->atPath('tools')
                ->addViolation();
        }
    }
}
