<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated tool arguments for the ask_human tool.
 *
 * LLM-provided snake_case argument keys are mapped to camelCase DTO
 * properties via the global Serializer camel_case_to_snake_case name converter.
 *
 * Answer schema is not accepted as raw input — free-form omits kind/choices,
 * confirm uses kind=confirm, and a non-empty choices list selects choice mode.
 * The conditional checks are declarative: kind="confirm" excludes any
 * choices list (including an empty one), and a provided choices list must be
 * non-empty with string elements only.
 */
final class AskHumanArgumentsDTO
{
    /**
     * @param list<string>|null $choices Non-empty answer choices as simple strings, or null/omitted. Empty list rejected.
     */
    public function __construct(
        #[Schema(description: 'The clear, concise question to display to the user.')]
        #[Assert\NotBlank(message: 'The "question" parameter must be provided and non-empty.')]
        public readonly string $question = '',
        #[Schema(description: 'Optional. Set to "confirm" for yes/no or approval questions (boolean). Omit for free-form text or when providing "choices". Mutually exclusive with "choices".')]
        #[Assert\Choice(choices: ['confirm'], message: 'Unsupported kind "{{ value }}". Allowed: confirm.')]
        public readonly ?string $kind = null,
        /**
         * @var list<string>|null Non-empty answer choices as simple strings, or null/omitted. Empty list rejected.
         */
        #[Schema(description: 'Non-empty list of answer choices as simple strings. Providing choices selects choice mode; omit kind. Mutually exclusive with kind="confirm". Do not pass an empty list.')]
        #[Assert\When(
            expression: 'this.choices !== null and this.kind !== "confirm"',
            constraints: [
                new Assert\All(constraints: [
                    new Assert\Type('string', message: 'Each choice must be a non-empty string.'),
                    new Assert\NotBlank(message: 'Each choice must be a non-empty string.'),
                ]),
                new Assert\Count(min: 1, minMessage: 'At least one choice is required when "choices" is provided.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.kind === "confirm"',
            constraints: [
                new Assert\IsNull(message: 'Cannot provide both kind="confirm" and "choices"; they are mutually exclusive.'),
            ],
        )]
        public readonly ?array $choices = null,
        #[Schema(description: 'Optional header text shown above the question in the UI.')]
        public readonly ?string $header = null,
    ) {
    }
}
