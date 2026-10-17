<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/**
 * Inline body or sealed/native immutable body reference.
 *
 * Paths are never caller-controlled. Owned sealed bodies use opaque storage keys.
 * Native artifact handoffs bind parent/artifact/handoff generation identities.
 */
final readonly class RetainedToolCompletionBodyReference
{
    /**
     * @param array<string, mixed>|null $inline
     */
    public function __construct(
        public RetainedToolCompletionBodyKindEnum $kind,
        public string $mediaType,
        public int $bytes,
        public string $sha256,
        public ?array $inline = null,
        public ?string $ownedBodyId = null,
        public ?string $owningRootSessionId = null,
        public ?string $nativeParentRunId = null,
        public ?string $nativeArtifactId = null,
        public ?string $nativeHandoffId = null,
    ) {
        if ($this->bytes < 0) {
            throw new \InvalidArgumentException('Body reference bytes must be non-negative.');
        }
        if (64 !== \strlen($this->sha256) || 1 !== preg_match('/^[a-f0-9]{64}$/', $this->sha256)) {
            throw new \InvalidArgumentException('Body reference sha256 must be a lowercase hex digest.');
        }
        if ('' === trim($this->mediaType)) {
            throw new \InvalidArgumentException('Body reference mediaType must be nonblank.');
        }

        match ($this->kind) {
            RetainedToolCompletionBodyKindEnum::Inline => $this->assertInline(),
            RetainedToolCompletionBodyKindEnum::OwnedSealed => $this->assertOwnedSealed(),
            RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff => $this->assertNativeArtifactHandoff(),
        };
    }

    /**
     * @param array<string, mixed> $inline
     */
    public static function inline(array $inline, string $mediaType, int $bytes, string $sha256): self
    {
        return new self(
            kind: RetainedToolCompletionBodyKindEnum::Inline,
            mediaType: $mediaType,
            bytes: $bytes,
            sha256: $sha256,
            inline: $inline,
        );
    }

    public static function ownedSealed(
        string $owningRootSessionId,
        string $ownedBodyId,
        string $mediaType,
        int $bytes,
        string $sha256,
    ): self {
        return new self(
            kind: RetainedToolCompletionBodyKindEnum::OwnedSealed,
            mediaType: $mediaType,
            bytes: $bytes,
            sha256: $sha256,
            ownedBodyId: $ownedBodyId,
            owningRootSessionId: $owningRootSessionId,
        );
    }

    public static function nativeArtifactHandoff(
        string $nativeParentRunId,
        string $nativeArtifactId,
        string $nativeHandoffId,
        string $mediaType,
        int $bytes,
        string $sha256,
    ): self {
        return new self(
            kind: RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff,
            mediaType: $mediaType,
            bytes: $bytes,
            sha256: $sha256,
            nativeParentRunId: $nativeParentRunId,
            nativeArtifactId: $nativeArtifactId,
            nativeHandoffId: $nativeHandoffId,
        );
    }

    public static function fromNativeGeneration(RetainedNativeHandoffGeneration $generation): self
    {
        return self::nativeArtifactHandoff(
            nativeParentRunId: $generation->parentRunId,
            nativeArtifactId: $generation->artifactId,
            nativeHandoffId: $generation->handoffId,
            mediaType: $generation->mediaType,
            bytes: $generation->bytes,
            sha256: $generation->sha256,
        );
    }

    private function assertInline(): void
    {
        if (null === $this->inline) {
            throw new \InvalidArgumentException('Inline body reference requires inline payload.');
        }
        if (null !== $this->ownedBodyId || null !== $this->owningRootSessionId
            || null !== $this->nativeParentRunId || null !== $this->nativeArtifactId || null !== $this->nativeHandoffId) {
            throw new \InvalidArgumentException('Inline body reference must not carry sealed or native fields.');
        }
    }

    private function assertOwnedSealed(): void
    {
        if (null === $this->ownedBodyId || '' === trim($this->ownedBodyId)
            || null === $this->owningRootSessionId || '' === trim($this->owningRootSessionId)) {
            throw new \InvalidArgumentException('Owned sealed body reference requires owning root and body id.');
        }
        if (null !== $this->inline
            || null !== $this->nativeParentRunId || null !== $this->nativeArtifactId || null !== $this->nativeHandoffId) {
            throw new \InvalidArgumentException('Owned sealed body reference must not carry inline or native fields.');
        }
        foreach (['ownedBodyId' => $this->ownedBodyId, 'owningRootSessionId' => $this->owningRootSessionId] as $field => $value) {
            if (false !== strpbrk($value, "/\\\0") || '.' === $value || '..' === $value) {
                throw new \InvalidArgumentException(\sprintf('Owned sealed %s must be a path-safe opaque component.', $field));
            }
        }
    }

    private function assertNativeArtifactHandoff(): void
    {
        if (null === $this->nativeParentRunId || '' === trim($this->nativeParentRunId)
            || null === $this->nativeArtifactId || '' === trim($this->nativeArtifactId)
            || null === $this->nativeHandoffId || '' === trim($this->nativeHandoffId)) {
            throw new \InvalidArgumentException('Native artifact handoff reference requires parent, artifact, and handoff ids.');
        }
        if (null !== $this->inline || null !== $this->ownedBodyId || null !== $this->owningRootSessionId) {
            throw new \InvalidArgumentException('Native artifact handoff reference must not carry inline or sealed fields.');
        }
        foreach ([
            'nativeParentRunId' => $this->nativeParentRunId,
            'nativeArtifactId' => $this->nativeArtifactId,
            'nativeHandoffId' => $this->nativeHandoffId,
        ] as $field => $value) {
            if (false !== strpbrk($value, "/\\\0") || '.' === $value || '..' === $value) {
                throw new \InvalidArgumentException(\sprintf('Native handoff %s must be a path-safe opaque component.', $field));
            }
        }
    }
}
