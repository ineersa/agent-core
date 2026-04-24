<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\ArtifactStoreInterface;
use Ineersa\AgentCore\Domain\Artifact\ArtifactMetadata;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class LocalArtifactStore implements ArtifactStoreInterface
{
    public function __construct(
        private string $basePath,
        private SerializerInterface $serializer,
    ) {
    }

    public function put(string $runId, string $artifactName, string $content, ?ArtifactMetadata $metadata = null): string
    {
        $safeName = preg_replace('{[^a-zA-Z0-9._-]}', '_', $artifactName) ?? 'artifact.bin';
        $runDir = rtrim($this->basePath, '/').'/'.$runId;

        if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
            throw new \RuntimeException(\sprintf('Failed to create artifact directory "%s".', $runDir));
        }

        $path = $runDir.'/'.$safeName;
        file_put_contents($path, $content);

        if (
            null !== $metadata
            && (
                null !== $metadata->mediaType
                || null !== $metadata->encoding
                || null !== $metadata->checksum
                || null !== $metadata->sizeBytes
                || null !== $metadata->attributes
            )
        ) {
            $metadataJson = $this->serializer->serialize(
                $metadata,
                'json',
                [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
            );

            file_put_contents($path.'.meta.json', $metadataJson);
        }

        return $path;
    }
}
