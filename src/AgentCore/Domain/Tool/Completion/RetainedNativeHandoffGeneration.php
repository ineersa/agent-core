<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/**
 * Host-authored immutable native handoff generation metadata.
 *
 * Produced by the artifact registry at write time. Never scraped from tool output.
 */
final readonly class RetainedNativeHandoffGeneration
{
    public function __construct(
        public string $parentRunId,
        public string $artifactId,
        public string $handoffId,
        public int $bytes,
        public string $sha256,
        public string $mediaType = 'text/markdown',
    ) {
        foreach ([
            'parentRunId' => $this->parentRunId,
            'artifactId' => $this->artifactId,
            'handoffId' => $this->handoffId,
            'mediaType' => $this->mediaType,
        ] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Native handoff generation %s must be nonblank.', $field));
            }
            if (false !== strpbrk($value, "/\\\0") && 'mediaType' !== $field) {
                throw new \InvalidArgumentException(\sprintf('Native handoff generation %s must not contain path separators.', $field));
            }
        }
        if ($this->bytes < 0) {
            throw new \InvalidArgumentException('Native handoff generation bytes must be non-negative.');
        }
        if (64 !== \strlen($this->sha256) || 1 !== preg_match('/^[a-f0-9]{64}$/', $this->sha256)) {
            throw new \InvalidArgumentException('Native handoff generation sha256 must be a lowercase hex digest.');
        }
    }
}
