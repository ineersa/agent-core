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
    private function __construct(
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'kind' => $this->kind->value,
            'media_type' => $this->mediaType,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
        ];

        return match ($this->kind) {
            RetainedToolCompletionBodyKindEnum::Inline => $payload + ['inline' => $this->inline],
            RetainedToolCompletionBodyKindEnum::OwnedSealed => $payload + [
                'owning_root_session_id' => $this->owningRootSessionId,
                'owned_body_id' => $this->ownedBodyId,
            ],
            RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff => $payload + [
                'native_parent_run_id' => $this->nativeParentRunId,
                'native_artifact_id' => $this->nativeArtifactId,
                'native_handoff_id' => $this->nativeHandoffId,
            ],
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $kindRaw = $payload['kind'] ?? null;
        if (!\is_string($kindRaw)) {
            throw new \InvalidArgumentException('Body reference kind must be a string.');
        }
        $kind = RetainedToolCompletionBodyKindEnum::tryFrom($kindRaw);
        if (null === $kind) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, \sprintf('Unsupported retained body kind "%s".', $kindRaw));
        }

        $mediaType = self::requireString($payload, 'media_type');
        $bytes = self::requireInt($payload, 'bytes');
        $sha256 = self::requireString($payload, 'sha256');

        return match ($kind) {
            RetainedToolCompletionBodyKindEnum::Inline => self::inline(
                self::requireArray($payload, 'inline'),
                $mediaType,
                $bytes,
                $sha256,
            ),
            RetainedToolCompletionBodyKindEnum::OwnedSealed => self::ownedSealed(
                self::requireString($payload, 'owning_root_session_id'),
                self::requireString($payload, 'owned_body_id'),
                $mediaType,
                $bytes,
                $sha256,
            ),
            RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff => self::nativeArtifactHandoff(
                self::requireString($payload, 'native_parent_run_id'),
                self::requireString($payload, 'native_artifact_id'),
                self::requireString($payload, 'native_handoff_id'),
                $mediaType,
                $bytes,
                $sha256,
            ),
        };
    }

    private function assertInline(): void
    {
        if (!\is_array($this->inline)) {
            throw new \InvalidArgumentException('Inline body reference requires inline content.');
        }
        if (null !== $this->ownedBodyId || null !== $this->owningRootSessionId
            || null !== $this->nativeParentRunId || null !== $this->nativeArtifactId || null !== $this->nativeHandoffId) {
            throw new \InvalidArgumentException('Inline body reference cannot carry sealed or native identities.');
        }
    }

    private function assertOwnedSealed(): void
    {
        if (null === $this->ownedBodyId || '' === trim($this->ownedBodyId)
            || null === $this->owningRootSessionId || '' === trim($this->owningRootSessionId)) {
            throw new \InvalidArgumentException('Owned sealed body reference requires owning root and body id.');
        }
        if (null !== $this->inline || null !== $this->nativeParentRunId
            || null !== $this->nativeArtifactId || null !== $this->nativeHandoffId) {
            throw new \InvalidArgumentException('Owned sealed body reference cannot carry inline or native identities.');
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
            throw new \InvalidArgumentException('Native artifact handoff reference cannot carry inline or owned sealed identities.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function requireArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('Body reference %s must be an array.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('Body reference %s must be a nonblank string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function requireInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('Body reference %s must be an int.', $key));
        }

        return $value;
    }
}
