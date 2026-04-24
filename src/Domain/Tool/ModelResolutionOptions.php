<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ModelResolutionOptions
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    /**
     * @param list<string> $keys
     */
    public function withoutKeys(array $keys): self
    {
        $filtered = $this->values;

        foreach ($keys as $key) {
            unset($filtered[$key]);
        }

        return new self($filtered);
    }
}
