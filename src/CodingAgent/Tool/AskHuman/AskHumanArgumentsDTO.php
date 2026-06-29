<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated tool arguments for the ask_human tool.
 *
 * LLM-provided snake_case argument keys are mapped to camelCase DTO
 * properties via the Serializer's SerializedName attribute.
 *
 * Note: The answer schema is NOT accepted as raw input — it is derived
 * internally from kind and choices. This avoids LLM errors with
 * embedded JSON Schema syntax.
 */
final class AskHumanArgumentsDTO
{
    /**
     * @param list<string>|null $choices Answer choices as simple strings. Structured objects rejected.
     */
    public function __construct(
        public readonly string $question = '',
        public readonly ?string $prompt = null,
        #[Assert\Choice(choices: ['text', 'confirm', 'choice', 'approval'], message: 'Unsupported ui_kind "{{ value }}". Allowed: text, confirm, choice, approval.')]
        #[SerializedName('ui_kind')]
        public readonly ?string $uiKind = null,
        #[Assert\Choice(choices: ['text', 'confirm', 'choice', 'approval'], message: 'Unsupported kind "{{ value }}". Allowed: text, confirm, choice, approval.')]
        public readonly ?string $kind = null,
        /**
         * @var list<string>|null Answer choices as simple strings. Structured objects rejected.
         */
        public readonly ?array $choices = null,
        public readonly mixed $default = null,
        #[SerializedName('question_id')]
        public readonly ?string $questionId = null,
        public readonly ?string $header = null,
        #[SerializedName('allow_other')]
        public readonly ?bool $allowOther = null,
    ) {
    }

    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        $hasQuestion = '' !== $this->question;
        $hasPrompt = null !== $this->prompt && '' !== $this->prompt;

        if (!$hasQuestion && !$hasPrompt) {
            $context->buildViolation('Either "question" or "prompt" must be provided and non-empty.')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateChoices(ExecutionContextInterface $context): void
    {
        $kind = $this->kind ?? $this->uiKind ?? null;

        if (null === $this->choices) {
            if ('choice' === $kind) {
                $context->buildViolation('The "choices" parameter is required when kind is "choice".')
                    ->addViolation();
            }

            return;
        }

        if ([] === $this->choices) {
            if ('choice' === $kind) {
                $context->buildViolation('At least one choice is required when kind is "choice".')
                    ->addViolation();
            }

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
