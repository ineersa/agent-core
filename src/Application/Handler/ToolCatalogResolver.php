<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolCatalogProviderInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCatalogContext;
use Ineersa\AgentCore\Domain\Tool\ToolDefinition;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class ToolCatalogResolver
{
    /** @var array<string, string> */
    private array $schemaFingerprintByName = [];

    /**
     * Injects provider iterable for tool catalog resolution.
     *
     * @param iterable<ToolCatalogProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    /**
     * Aggregates tool definitions from all registered providers.
     *
     * @return list<ToolDefinition>
     */
    public function resolve(?ToolCatalogContext $context = null): array
    {
        $context ??= new ToolCatalogContext();
        $contextDescriptors = $this->normalizer->normalize($context);
        \assert(\is_array($contextDescriptors));

        $contextDescriptors['runId'] ??= $context->runId;
        $contextDescriptors['turnNo'] ??= $context->turnNo;
        $contextDescriptors['stepId'] ??= $context->stepId;
        $contextDescriptors['contextRef'] ??= $context->contextRef;
        $contextDescriptors['toolsRef'] ??= $context->toolsRef;

        /** @var array<string, ToolDefinition> $resolved */
        $resolved = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->resolveToolCatalog($context) as $definition) {
                $this->assertSchemaStability($definition, $contextDescriptors);
                $resolved[$definition->name] = $definition;
            }
        }

        return array_values($resolved);
    }

    /**
     * Retrieves and validates payload from a single provider.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveProviderPayload(?ToolCatalogContext $context = null): array
    {
        return array_map(
            static fn (ToolDefinition $definition): array => $definition->toProviderPayload(),
            $this->resolve($context),
        );
    }

    /**
     * @param array<string, mixed> $contextDescriptors
     */
    private function assertSchemaStability(ToolDefinition $definition, array $contextDescriptors): void
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

        $runId = (string) ($contextDescriptors['run_id'] ?? $contextDescriptors['runId'] ?? '');
        $turnNo = $contextDescriptors['turn_no'] ?? $contextDescriptors['turnNo'] ?? null;
        $stepId = (string) ($contextDescriptors['step_id'] ?? $contextDescriptors['stepId'] ?? '');

        throw new \LogicException(\sprintf('Tool "%s" schema changed across turns. Keep name/schema stable and only vary description. Context: run_id=%s turn_no=%s step_id=%s', $toolName, $runId, null === $turnNo ? '' : (string) $turnNo, $stepId));
    }

    /**
     * Computes deterministic hash of a tool schema array.
     *
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
