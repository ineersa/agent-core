<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolCatalogProviderInterface;
use Ineersa\AgentCore\Domain\Tool\ToolDefinition;

final class ToolCatalogResolver
{
    /** @var array<string, string> */
    private array $schemaFingerprintByName = [];

    /**
     * @param iterable<ToolCatalogProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<ToolDefinition>
     */
    public function resolve(array $context = []): array
    {
        /** @var array<string, ToolDefinition> $resolved */
        $resolved = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->resolveToolCatalog($context) as $definition) {
                $this->assertSchemaStability($definition);
                $resolved[$definition->name] = $definition;
            }
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array<string, mixed>>
     */
    public function resolveProviderPayload(array $context = []): array
    {
        return array_map(
            static fn (ToolDefinition $definition): array => $definition->toProviderPayload(),
            $this->resolve($context),
        );
    }

    private function assertSchemaStability(ToolDefinition $definition): void
    {
        $fingerprint = $this->schemaFingerprint($definition->schema);
        $toolName = $definition->name;

        if (!isset($this->schemaFingerprintByName[$toolName])) {
            $this->schemaFingerprintByName[$toolName] = $fingerprint;

            return;
        }

        if ($this->schemaFingerprintByName[$toolName] === $fingerprint) {
            return;
        }

        throw new \LogicException(\sprintf('Tool "%s" schema changed across turns. Keep name/schema stable and only vary description.', $toolName));
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private function schemaFingerprint(?array $schema): string
    {
        $encoded = json_encode($schema ?? []);
        if (false === $encoded) {
            $encoded = serialize($schema ?? []);
        }

        return hash('sha256', $encoded);
    }
}
