<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Model;

/**
 * Completed non-streaming model call result for extensions.
 *
 * @phpstan-type StructuredContentObject array<string, mixed>
 * @phpstan-type StructuredContentList list<mixed>
 */
final readonly class ModelCallResultDTO
{
    /**
     * @param list<ModelToolCallDTO>                             $toolCalls
     * @param StructuredContentObject|StructuredContentList|null $structuredContent Parsed structured JSON when requested/available
     */
    public function __construct(
        public string $model,
        public string $content,
        public array $toolCalls = [],
        public ?array $structuredContent = null,
    ) {
        if ('' === $this->model) {
            throw new \InvalidArgumentException('model must not be empty.');
        }
        foreach ($this->toolCalls as $toolCall) {
            if (!$toolCall instanceof ModelToolCallDTO) {
                throw new \InvalidArgumentException('toolCalls must contain ModelToolCallDTO instances.');
            }
        }
    }
}
