<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\ArtifactStoreInterface;

final readonly class LocalArtifactStore implements ArtifactStoreInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function put(string $runId, string $artifactName, string $content, array $metadata = []): string
    {
        $safeName = preg_replace('{[^a-zA-Z0-9._-]}', '_', $artifactName) ?? 'artifact.bin';
        $runDir = rtrim($this->basePath, '/').'/'.$runId;

        if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
            throw new \RuntimeException(\sprintf('Failed to create artifact directory "%s".', $runDir));
        }

        $path = $runDir.'/'.$safeName;
        file_put_contents($path, $content);

        if ([] !== $metadata) {
            $metadataJson = json_encode($metadata);
            if (false !== $metadataJson) {
                file_put_contents($path.'.meta.json', $metadataJson);
            }
        }

        return $path;
    }
}
