<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Model;

/**
 * Completed non-streaming model call result for extensions.
 *
 * @phpstan-type StructuredContent array<string, mixed>|list<mixed>|null
 */
final readonly class ModelCallResultDTO
{
    /**
     * @param list<ModelToolCallDTO> $toolCalls
     * @param StructuredContent      $structuredContent
     */
    public function __construct(
        public string $model,
        public string $content,
        public array $toolCalls = [],
        public mixed $structuredContent = null,
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
