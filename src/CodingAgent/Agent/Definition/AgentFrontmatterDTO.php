<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Normalized frontmatter DTO — the raw YAML fields after denormalization
 * and validation by Symfony Serializer + Validator.
 *
 * This DTO represents the user-supplied YAML frontmatter only.  It does NOT
 * include parser-added metadata (instructions, sourcePath).
 *
 * All validation constraints are declared as PHP attributes so Symfony
 * Validator can enforce them without manual is_* checks.
 *
 * @internal
 */
final class AgentFrontmatterDTO
{
    /**
     * @param list<string>|null $tools      null means inherit all parent-available tools at launch (pi parity)
     * @param list<string>      $skills
     * @param list<string>|null $extensions null means no optional child extensions (always_on only)
     */
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

        #[Assert\When(
            expression: 'this.tools !== null',
            constraints: [
                new Assert\Count(min: 1, minMessage: '"tools" must be a non-empty list of strings.'),
                new Assert\All([
                    new Assert\Type('string', '"tools[{{ index }}]" must be a string.'),
                    new Assert\NotBlank(message: '"tools[{{ index }}]" must not be empty.'),
                    new Assert\Regex(
                        pattern: '/^\\S+(\\s+\\S+)*$/',
                        message: '"tools[{{ index }}]" must not have leading or trailing whitespace.',
                    ),
                ]),
            ],
        )]
        public readonly ?array $tools = null,

        // --- Optional fields with defaults ---

        public readonly ?string $model = null,

        #[Assert\Choice(
            choices: ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            message: '"thinking" must be one of off|minimal|low|medium|high|xhigh|max.',
        )]
        public readonly ?string $thinking = null,

        #[Assert\All([
            new Assert\Type('string', '"skills[{{ index }}]" must be a string.'),
            new Assert\NotBlank(message: '"skills[{{ index }}]" must not be empty.'),
            new Assert\Regex(
                pattern: '/^\\S+(\\s+\\S+)*$/',
                message: '"skills[{{ index }}]" must not have leading or trailing whitespace.',
            ),
        ])]
        public readonly array $skills = [],

        #[Assert\When(
            expression: 'this.extensions !== null',
            constraints: [
                new Assert\All([
                    new Assert\Type('string', '"extensions[{{ index }}]" must be a string.'),
                    new Assert\NotBlank(message: '"extensions[{{ index }}]" must not be empty.'),
                    new Assert\Regex(
                        pattern: '/^\\S+$/',
                        message: '"extensions[{{ index }}]" must not have leading or trailing whitespace.',
                    ),
                ]),
            ],
        )]
        public readonly ?array $extensions = null,

        #[SerializedName('inheritProjectContext')]
        #[Assert\Type('bool', '"inheritProjectContext" must be a boolean.')]
        public readonly bool $inheritProjectContext = true,

        #[SerializedName('systemPromptMode')]
        #[Assert\Choice(
            choices: ['replace', 'append'],
            message: '"systemPromptMode" must be one of replace|append.',
        )]
        public readonly string $systemPromptMode = 'replace',

        #[SerializedName('parallelAllowed')]
        #[Assert\Type('bool', '"parallelAllowed" must be a boolean.')]
        public readonly bool $parallelAllowed = true,
    ) {
    }

    #[Assert\Callback]
    public function validateCrossField(ExecutionContextInterface $context): void
    {
        if (null !== $this->tools && !array_is_list($this->tools)) {
            $context->buildViolation('Must be a list (sequential array), got associative array.')
                ->atPath('tools')
                ->addViolation();
        }

        if (!array_is_list($this->skills)) {
            $context->buildViolation('Must be a list (sequential array), got associative array.')
                ->atPath('skills')
                ->addViolation();
        }

        if (null !== $this->extensions && !array_is_list($this->extensions)) {
            $context->buildViolation('Must be a list (sequential array), got associative array.')
                ->atPath('extensions')
                ->addViolation();
        }
    }
}
