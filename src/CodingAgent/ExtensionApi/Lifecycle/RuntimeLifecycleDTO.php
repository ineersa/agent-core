<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Owning-runtime lifecycle notification for extensions.
 */
final readonly class RuntimeLifecycleDTO
{
    /**
     * @param array<string, scalar|list<mixed>|array<string, mixed>|null> $metadata JSON-safe correlation metadata only
     */
    public function __construct(
        public RuntimeLifecyclePhaseEnum $phase,
        public string $ownerKind,
        public \DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {
        if ('' === $this->ownerKind) {
            throw new \InvalidArgumentException('ownerKind must not be empty.');
        }
    }
}
