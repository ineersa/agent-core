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
 */
final class AskHumanArgumentsDTO
{
    /**
     * @param array<string, mixed>|null               $schema  JSON Schema describing the expected answer format
     * @param array<array<string, mixed>|string>|null $choices Raw choices before normalization
     */
    public function __construct(
        public readonly string $question = '',
        public readonly ?string $prompt = null,
        #[Assert\Choice(choices: ['text', 'confirm', 'choice', 'approval'], message: 'Unsupported ui_kind "{{ value }}". Allowed: text, confirm, choice, approval.')]
        #[SerializedName('ui_kind')]
        public readonly ?string $uiKind = null,
        #[Assert\Choice(choices: ['text', 'confirm', 'choice', 'approval'], message: 'Unsupported kind "{{ value }}". Allowed: text, confirm, choice, approval.')]
        public readonly ?string $kind = null,
        public readonly ?array $schema = null,
        public readonly ?array $choices = null,
        public readonly mixed $default = null,
        #[SerializedName('question_id')]
        public readonly ?string $questionId = null,
        public readonly ?string $header = null,
        #[SerializedName('allow_other')]
        public readonly ?bool $allowOther = null,
        public readonly ?bool $secret = null,
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
}
