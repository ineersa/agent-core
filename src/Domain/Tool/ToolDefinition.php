<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Defines the immutable structure and metadata for an agent tool, including its name, description, and JSON schema. This class serves as the domain model for tool configuration within the AgentCore system.
 */
final readonly class ToolDefinition
{
    /**
     * Initializes the tool definition with name, description, and optional schema.
     *
     * @param array<string, mixed>|null $schema
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?array $schema = null,
    ) {
    }

    /**
     * Converts the tool definition into a provider-compatible array payload.
     *
     * @return array{
     * type: 'function',
     * function: array{
     * name: string,
     * description: string,
     * parameters?: array<string, mixed>
     * }
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
