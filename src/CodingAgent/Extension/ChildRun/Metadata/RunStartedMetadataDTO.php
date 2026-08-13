<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Typed RunStarted metadata (payload.payload.metadata) for CodingAgent consumers.
 *
 * Hydrated by Symfony Serializer at the RunEvent boundary. Does not replace
 * generic {@see \Ineersa\AgentCore\Domain\Run\RunMetadata} write-side construction.
 */
final readonly class RunStartedMetadataDTO
{
    /**
     * @param list<string>|null $extensions
     */
    public function __construct(
        public RunStartedSessionMetadataDTO $session = new RunStartedSessionMetadataDTO(),
        public ?string $model = null,
        public ?string $reasoning = null,
        #[SerializedName('tools_scope')]
        public ?RunStartedToolsScopeDTO $toolsScope = null,
        #[SerializedName('context_window')]
        public ?int $contextWindow = null,
        public ?array $extensions = null,
        public ?string $provider = null,
    ) {
    }

    /**
     * One generic-envelope extraction from RunEvent.payload, then Serializer
     * denormalizes the stable nested metadata object graph.
     *
     * @param array<string, mixed> $eventPayload Full RunEvent.payload for run_started
     */
    public static function tryFromRunEventPayload(array $eventPayload, DenormalizerInterface $denormalizer): ?self
    {
        $inner = $eventPayload['payload'] ?? null;
        if (!\is_array($inner)) {
            return null;
        }

        $metadata = $inner['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return null;
        }

        try {
            $dto = $denormalizer->denormalize($metadata, self::class);
        } catch (SerializerExceptionInterface|\TypeError|\ValueError|\InvalidArgumentException) {
            return null;
        }

        return $dto instanceof self ? $dto : null;
    }

    public function isAgentChild(): bool
    {
        return $this->session->isAgentChild();
    }

    /**
     * Child tool allowlist, or null when not a child / tools_scope.allowed_tools missing.
     *
     * @return list<string>|null
     */
    public function allowedToolsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }
        if (null === $this->toolsScope || null === $this->toolsScope->allowedTools) {
            return null;
        }

        return $this->toolsScope->allowedTools;
    }

    /**
     * Child extension allowlist:
     * - null when not an agent child
     * - empty list when extensions absent (fail closed) or empty
     * - non-empty list when present
     *
     * @return list<string>|null
     */
    public function allowedExtensionsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }

        return $this->extensions ?? [];
    }
}
