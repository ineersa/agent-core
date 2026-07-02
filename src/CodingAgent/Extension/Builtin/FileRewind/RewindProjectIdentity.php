<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

/**
 * Stable identity for a project root used in hidden snapshot storage paths.
 */
final readonly class RewindProjectIdentity
{
    public function __construct(
        public string $projectRoot,
        public string $projectHash,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $real = realpath($projectRoot);
        $normalized = str_replace('\\', '/', false !== $real ? $real : $projectRoot);

        return new self(
            projectRoot: $normalized,
            projectHash: hash('sha256', $normalized),
        );
    }
}
