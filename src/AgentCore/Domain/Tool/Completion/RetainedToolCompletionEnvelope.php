<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/**
 * Immutable versioned tool_completion evidence retained with the accepted receipt.
 *
 * message_id equals completion_id. Runtime origin/provenance are not output-controlled.
 * accepted_source_event_reference is allocated by event sequencing and excluded from the
 * semantic fingerprint.
 */
final readonly class RetainedToolCompletionEnvelope
{
    /**
     * @param list<array{kind: string, reference: array<string, mixed>}> $attachments
     */
    public function __construct(
        public string $messageId,
        public RetainedToolCompletionOrigin $origin,
        public RetainedToolCompletionPresentation $presentation,
        public RetainedToolCompletionBodyReference $body,
        public RetainedToolCompletionIntegrity $integrity,
        public RetainedToolCompletionProvenance $provenance = new RetainedToolCompletionProvenance(),
        public array $attachments = [],
        public int $schemaVersion = RetainedToolCompletionBounds::SCHEMA_VERSION,
        public string $kind = RetainedToolCompletionBounds::KIND_TOOL_COMPLETION,
    ) {
        if ('' === trim($this->messageId)) {
            throw new \InvalidArgumentException('Retained envelope messageId must be nonblank.');
        }
        if (RetainedToolCompletionBounds::KIND_TOOL_COMPLETION !== $this->kind) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, \sprintf('Unsupported retained message kind "%s".', $this->kind));
        }
        if (RetainedToolCompletionBounds::SCHEMA_VERSION !== $this->schemaVersion) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, \sprintf('Unsupported retained message schema_version %d.', $this->schemaVersion));
        }
        foreach ($this->attachments as $attachment) {
            if (!\is_array($attachment)) {
                throw new \InvalidArgumentException('Retained envelope attachment entries must be arrays.');
            }
            $attachmentKind = $attachment['kind'] ?? null;
            $attachmentReference = $attachment['reference'] ?? null;
            if (!\is_string($attachmentKind) || '' === trim($attachmentKind) || !\is_array($attachmentReference)) {
                throw new \InvalidArgumentException('Retained envelope attachments require kind and reference.');
            }
        }
    }

    public function withAcceptedSourceEventReference(RetainedToolCompletionSourceEventReference $reference): self
    {
        return new self(
            messageId: $this->messageId,
            origin: $this->origin->withAcceptedSourceEventReference($reference),
            presentation: $this->presentation,
            body: $this->body,
            integrity: $this->integrity,
            provenance: $this->provenance,
            attachments: $this->attachments,
            schemaVersion: $this->schemaVersion,
            kind: $this->kind,
        );
    }

    /**
     * Semantic fingerprint object excludes accepted_source_event_reference and integrity digests.
     */
    public function forSemanticFingerprint(): self
    {
        return new self(
            messageId: $this->messageId,
            origin: $this->origin->withoutAcceptedSourceEventReference(),
            presentation: $this->presentation,
            body: $this->body,
            integrity: new RetainedToolCompletionIntegrity(str_repeat('0', 64)),
            provenance: $this->provenance,
            attachments: $this->attachments,
            schemaVersion: $this->schemaVersion,
            kind: $this->kind,
        );
    }
}
