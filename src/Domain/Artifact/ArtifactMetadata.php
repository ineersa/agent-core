<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Artifact;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class ArtifactMetadata
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null>|null $attributes
     */
    public function __construct(
        #[SerializedName('media_type')]
        public ?string $mediaType = null,
        public ?string $encoding = null,
        public ?string $checksum = null,
        #[SerializedName('size_bytes')]
        public ?int $sizeBytes = null,
        public ?array $attributes = null,
    ) {
    }
}
