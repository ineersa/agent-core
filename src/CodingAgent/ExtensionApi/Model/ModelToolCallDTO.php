<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Model;

/**
 * One tool call requested by a model response.
 *
 * Tools are never executed by callModel(); the extension owns any tool loop.
 *
 * @phpstan-type ToolArguments array<string, mixed>
 */
final readonly class ModelToolCallDTO
{
    /**
     * @param ToolArguments $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('tool call id must not be empty.');
        }
        if ('' === $this->name) {
            throw new \InvalidArgumentException('tool call name must not be empty.');
        }
    }
}
