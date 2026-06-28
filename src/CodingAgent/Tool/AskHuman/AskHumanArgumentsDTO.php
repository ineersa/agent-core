<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

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
        #[Assert\NotBlank(message: 'The "question" parameter is required and must be non-empty.')]
        public readonly string $question = '',
        public readonly ?string $prompt = null,
        #[SerializedName('ui_kind')]
        public readonly ?string $uiKind = null,
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
}
