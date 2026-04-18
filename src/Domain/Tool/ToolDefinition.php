<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed>|null $schema
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?array $schema = null,
    ) {
    }

    /**
     * @return array{
     *   type: 'function',
     *   function: array{
     *     name: string,
     *     description: string,
     *     parameters?: array<string, mixed>
     *   }
     * }
     */
    public function toProviderPayload(): array
    {
        $function = [
            'name' => $this->name,
            'description' => $this->description,
        ];

        if (null !== $this->schema) {
            $function['parameters'] = $this->schema;
        }

        return [
            'type' => 'function',
            'function' => $function,
        ];
    }
}
