<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated tool arguments for the ask_human tool.
 *
 * LLM-provided snake_case argument keys are mapped to camelCase DTO
 * properties via the global Serializer camel_case_to_snake_case name converter.
 *
 * Answer schema is not accepted as raw input — free-form omits kind/choices,
 * confirm uses kind=confirm, and a non-empty choices list selects choice mode.
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
        public readonly ?array $choices = null,
        #[Schema(description: 'Optional header text shown above the question in the UI.')]
        public readonly ?string $header = null,
    ) {
    }

    #[Assert\Callback]
    public function validateChoices(ExecutionContextInterface $context): void
    {
        if (null === $this->choices) {
            return;
        }

        // confirm + any provided choices (including []) is contradictory; emit only the conflict.
        if ('confirm' === $this->kind) {
            $context->buildViolation('Cannot provide both kind="confirm" and "choices"; they are mutually exclusive.')
                ->addViolation();

            return;
        }

        if ([] === $this->choices) {
            $context->buildViolation('At least one choice is required when "choices" is provided.')
                ->addViolation();

            return;
        }

        foreach ($this->choices as $i => $choice) {
            if (!\is_string($choice) || '' === $choice) {
                $context->buildViolation('Each choice must be a non-empty string.')
                    ->atPath('choices['.$i.']')
                    ->addViolation();
            }
        }
    }
}
