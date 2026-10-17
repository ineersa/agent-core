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
        public string $terminalOutcome,
        public string $subject,
        public string $boundedSummary,
        public RetainedToolCompletionBodyReference $body,
        public string $payloadFingerprint,
        public string $producer = 'runtime',
        public string $payloadAuthority = 'external_tool_result',
        public string $severity = 'info',
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
        foreach (['terminalOutcome' => $this->terminalOutcome, 'producer' => $this->producer, 'payloadAuthority' => $this->payloadAuthority, 'severity' => $this->severity, 'payloadFingerprint' => $this->payloadFingerprint] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Retained envelope %s must be nonblank.', $field));
            }
        }
        RetainedToolCompletionUtf8::requireValid($this->subject, 'subject');
        RetainedToolCompletionUtf8::requireValid($this->boundedSummary, 'bounded_summary');
        if (RetainedToolCompletionUtf8::byteLength($this->subject) > RetainedToolCompletionBounds::SUBJECT_MAX_BYTES) {
            throw new \InvalidArgumentException('Retained envelope subject exceeds byte budget.');
        }
        if (RetainedToolCompletionUtf8::byteLength($this->boundedSummary) > RetainedToolCompletionBounds::SUMMARY_MAX_BYTES) {
            throw new \InvalidArgumentException('Retained envelope summary exceeds byte budget.');
        }
        if (64 !== \strlen($this->payloadFingerprint) || 1 !== preg_match('/^[a-f0-9]{64}$/', $this->payloadFingerprint)) {
            throw new \InvalidArgumentException('Retained envelope payload fingerprint must be a lowercase hex digest.');
        }

        $serialized = json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        if (\strlen($serialized) > RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES) {
            throw new \InvalidArgumentException('Retained envelope exceeds 16 KiB budget.');
        }
    }

    public function withAcceptedSourceEventReference(RetainedToolCompletionSourceEventReference $reference): self
    {
        return new self(
            messageId: $this->messageId,
            origin: $this->origin->withAcceptedSourceEventReference($reference),
            terminalOutcome: $this->terminalOutcome,
            subject: $this->subject,
            boundedSummary: $this->boundedSummary,
            body: $this->body,
            payloadFingerprint: $this->payloadFingerprint,
            producer: $this->producer,
            payloadAuthority: $this->payloadAuthority,
            severity: $this->severity,
            attachments: $this->attachments,
            schemaVersion: $this->schemaVersion,
            kind: $this->kind,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'message_id' => $this->messageId,
            'kind' => $this->kind,
            'origin' => $this->origin->toArray(),
            'provenance' => [
                'producer' => $this->producer,
                'payload_authority' => $this->payloadAuthority,
            ],
            'presentation' => [
                'severity' => $this->severity,
                'terminal_outcome' => $this->terminalOutcome,
                'subject' => $this->subject,
                'bounded_summary' => $this->boundedSummary,
            ],
            'body' => $this->body->toArray(),
            'attachments' => $this->attachments,
            'integrity' => [
                'payload_fingerprint' => $this->payloadFingerprint,
            ],
        ];
    }

    /**
     * Semantic fingerprint payload excludes accepted_source_event_reference.
     *
     * @return array<string, mixed>
     */
    public function semanticFingerprintPayload(): array
    {
        $origin = $this->origin->toArray();
        unset($origin['accepted_source_event_reference']);

        return [
            'schema_version' => $this->schemaVersion,
            'message_id' => $this->messageId,
            'kind' => $this->kind,
            'origin' => $origin,
            'provenance' => [
                'producer' => $this->producer,
                'payload_authority' => $this->payloadAuthority,
            ],
            'presentation' => [
                'severity' => $this->severity,
                'terminal_outcome' => $this->terminalOutcome,
                'subject' => $this->subject,
                'bounded_summary' => $this->boundedSummary,
            ],
            'body' => $this->body->toArray(),
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!\is_int($schemaVersion)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Retained envelope schema_version must be an int.');
        }
        $kind = $payload['kind'] ?? null;
        if (!\is_string($kind)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Retained envelope kind must be a string.');
        }

        $originPayload = $payload['origin'] ?? null;
        if (!\is_array($originPayload)) {
            throw new \InvalidArgumentException('Retained envelope origin must be an array.');
        }
        $sourceRefPayload = $originPayload['accepted_source_event_reference'] ?? null;
        $sourceRef = null;
        if (\is_array($sourceRefPayload)) {
            $sourceRef = new RetainedToolCompletionSourceEventReference(
                runId: self::requireString($sourceRefPayload, 'run_id'),
                seq: self::requireInt($sourceRefPayload, 'seq'),
                turnNo: self::requireInt($sourceRefPayload, 'turn_no'),
                type: self::requireString($sourceRefPayload, 'type'),
            );
        }

        $origin = new RetainedToolCompletionOrigin(
            controllerRunId: self::requireString($originPayload, 'controller_run_id'),
            sourceRunId: self::requireString($originPayload, 'source_run_id'),
            originTurnNo: self::requireInt($originPayload, 'origin_turn_no'),
            originStepId: self::requireString($originPayload, 'origin_step_id'),
            toolCallId: self::requireString($originPayload, 'tool_call_id'),
            invocationId: self::requireString($originPayload, 'invocation_id'),
            executionOwnerKind: self::requireString($originPayload, 'execution_owner_kind'),
            acceptedSourceEventReference: $sourceRef,
        );

        $provenance = $payload['provenance'] ?? null;
        if (!\is_array($provenance)) {
            throw new \InvalidArgumentException('Retained envelope provenance must be an array.');
        }
        $presentation = $payload['presentation'] ?? null;
        if (!\is_array($presentation)) {
            throw new \InvalidArgumentException('Retained envelope presentation must be an array.');
        }
        $bodyPayload = $payload['body'] ?? null;
        if (!\is_array($bodyPayload)) {
            throw new \InvalidArgumentException('Retained envelope body must be an array.');
        }
        $integrity = $payload['integrity'] ?? null;
        if (!\is_array($integrity)) {
            throw new \InvalidArgumentException('Retained envelope integrity must be an array.');
        }
        $attachments = $payload['attachments'] ?? [];
        if (!\is_array($attachments)) {
            throw new \InvalidArgumentException('Retained envelope attachments must be an array.');
        }

        /** @var list<array{kind: string, reference: array<string, mixed>}> $normalizedAttachments */
        $normalizedAttachments = [];
        foreach ($attachments as $attachment) {
            if (!\is_array($attachment)) {
                throw new \InvalidArgumentException('Retained envelope attachment entries must be arrays.');
            }
            $attachmentKind = $attachment['kind'] ?? null;
            $attachmentReference = $attachment['reference'] ?? null;
            if (!\is_string($attachmentKind) || !\is_array($attachmentReference)) {
                throw new \InvalidArgumentException('Retained envelope attachments require kind and reference.');
            }
            $normalizedAttachments[] = [
                'kind' => $attachmentKind,
                'reference' => $attachmentReference,
            ];
        }

        return new self(
            messageId: self::requireString($payload, 'message_id'),
            origin: $origin,
            terminalOutcome: self::requireString($presentation, 'terminal_outcome'),
            subject: self::requireString($presentation, 'subject'),
            boundedSummary: self::requireString($presentation, 'bounded_summary'),
            body: RetainedToolCompletionBodyReference::fromArray($bodyPayload),
            payloadFingerprint: self::requireString($integrity, 'payload_fingerprint'),
            producer: self::requireString($provenance, 'producer'),
            payloadAuthority: self::requireString($provenance, 'payload_authority'),
            severity: self::requireString($presentation, 'severity'),
            attachments: $normalizedAttachments,
            schemaVersion: $schemaVersion,
            kind: $kind,
        );
    }

    /** @param array<string, mixed> $payload */
    private static function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('Retained envelope %s must be a nonblank string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function requireInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('Retained envelope %s must be an int.', $key));
        }

        return $value;
    }
}
