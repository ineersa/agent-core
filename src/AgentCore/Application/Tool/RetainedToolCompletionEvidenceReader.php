<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeArtifactHandoffReaderInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;

/**
 * Narrow internal reader over canonical accepted retained completion evidence.
 *
 * Looks up one ToolExecutionEnd by accepted completion identity. Does not load
 * full RunState or model history. Body resolution never follows raw result paths.
 */
final readonly class RetainedToolCompletionEvidenceReader implements RetainedToolCompletionEvidenceReaderInterface
{
    public function __construct(
        private EventStoreInterface $events,
        private RetainedToolCompletionEnvelopeCodec $codec,
        private RetainedToolCompletionBodyStoreInterface $bodyStore,
        private RetainedNativeArtifactHandoffReaderInterface $nativeHandoffs,
    ) {
    }

    public function read(
        string $authorizedRootSessionId,
        string $sourceRunId,
        string $messageId,
        ?int $maxBodyBytes = null,
        ?int $acceptedSourceSeq = null,
    ): array {
        if ('' === trim($authorizedRootSessionId) || '' === trim($sourceRunId) || '' === trim($messageId)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion lookup identity is incomplete.');
        }
        if (false !== strpbrk($authorizedRootSessionId, '/\\')
            || false !== strpbrk($sourceRunId, '/\\')
            || false !== strpbrk($messageId, '/\\')) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion lookup rejected path-like identity.');
        }

        $envelope = null;
        if (null !== $acceptedSourceSeq) {
            if ($acceptedSourceSeq < 1) {
                throw new \InvalidArgumentException('acceptedSourceSeq must be positive when provided.');
            }
            foreach ($this->events->rangeFor($sourceRunId, $acceptedSourceSeq, $acceptedSourceSeq) as $event) {
                $envelope = $this->envelopeFromEvent($event->type, $event->payload, $authorizedRootSessionId, $sourceRunId, $messageId);
                break;
            }
        } else {
            $scanned = 0;
            foreach ($this->events->reverseFor($sourceRunId) as $event) {
                ++$scanned;
                if ($scanned > RetainedToolCompletionBounds::SOURCE_LOOKUP_MAX_EVENTS) {
                    throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::NotFound, \sprintf('Retained completion "%s" was not found within the bounded source lookup window for run "%s".', $messageId, $sourceRunId));
                }
                $candidate = $this->envelopeFromEvent($event->type, $event->payload, $authorizedRootSessionId, $sourceRunId, $messageId);
                if (null !== $candidate) {
                    $envelope = $candidate;
                    break;
                }
            }
        }

        if (null === $envelope) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::NotFound, \sprintf('Retained completion "%s" was not found for run "%s".', $messageId, $sourceRunId));
        }

        return [
            'envelope' => $envelope,
            'body' => $this->resolveBody($envelope, $authorizedRootSessionId, $maxBodyBytes),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function envelopeFromEvent(
        string $type,
        array $payload,
        string $authorizedRootSessionId,
        string $sourceRunId,
        string $messageId,
    ): ?RetainedToolCompletionEnvelope {
        if (RunEventTypeEnum::ToolExecutionEnd->value !== $type) {
            return null;
        }
        $retained = $payload['accepted_completion']['retained_message'] ?? null;
        if (!\is_array($retained)) {
            return null;
        }
        $candidateId = $retained['message_id'] ?? null;
        if ($candidateId !== $messageId) {
            return null;
        }

        $decoded = $this->codec->denormalize($retained);
        if ($decoded->origin->controllerRunId !== $authorizedRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion controller scope does not match the authorized root session.');
        }
        if ($decoded->origin->sourceRunId !== $sourceRunId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion source run does not match the lookup run.');
        }

        $expectedFingerprint = $this->codec->payloadFingerprint($decoded);
        if ($expectedFingerprint !== $decoded->integrity->payloadFingerprint) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope failed semantic fingerprint validation.');
        }

        return $decoded;
    }

    /**
     * @return array{
     *     kind: string,
     *     media_type: string,
     *     bytes: int,
     *     sha256: string,
     *     truncated: bool,
     *     inline?: array<string, mixed>,
     *     json?: array<array-key, mixed>|string|int|float|bool|null,
     *     text?: string
     * }
     */
    private function resolveBody(
        RetainedToolCompletionEnvelope $envelope,
        string $authorizedRootSessionId,
        ?int $maxBodyBytes,
    ): array {
        $body = $envelope->body;
        $budget = $maxBodyBytes ?? RetainedToolCompletionBounds::INSPECTION_BODY_MAX_BYTES;
        if ($budget < 0) {
            throw new \InvalidArgumentException('maxBodyBytes must be non-negative.');
        }

        return match ($body->kind) {
            RetainedToolCompletionBodyKindEnum::Inline => [
                'kind' => $body->kind->value,
                'media_type' => $body->mediaType,
                'bytes' => $body->bytes,
                'sha256' => $body->sha256,
                'truncated' => $body->bytes > $budget,
                'inline' => $body->bytes > $budget ? [] : ($body->inline ?? []),
            ],
            RetainedToolCompletionBodyKindEnum::OwnedSealed => $this->resolveOwnedSealed($envelope, $authorizedRootSessionId, $budget),
            RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff => $this->resolveNative($envelope, $authorizedRootSessionId, $budget),
        };
    }

    /**
     * @return array{kind: string, media_type: string, bytes: int, sha256: string, truncated: bool, json?: array<array-key, mixed>|string|int|float|bool|null, text?: string}
     */
    private function resolveOwnedSealed(
        RetainedToolCompletionEnvelope $envelope,
        string $authorizedRootSessionId,
        int $budget,
    ): array {
        $read = $this->bodyStore->inspectJson($envelope->body, $authorizedRootSessionId, $budget);
        $result = [
            'kind' => $envelope->body->kind->value,
            'media_type' => $read['media_type'],
            'bytes' => $read['bytes'],
            'sha256' => $read['sha256'],
            'truncated' => $read['truncated'],
        ];
        if (\array_key_exists('json', $read)) {
            $result['json'] = $read['json'];
        }
        if (isset($read['text']) && \is_string($read['text'])) {
            $result['text'] = $read['text'];
        }

        return $result;
    }

    /**
     * @return array{kind: string, media_type: string, bytes: int, sha256: string, truncated: bool, text?: string}
     */
    private function resolveNative(
        RetainedToolCompletionEnvelope $envelope,
        string $authorizedRootSessionId,
        int $budget,
    ): array {
        $body = $envelope->body;
        $read = $this->nativeHandoffs->inspectHandoff(
            authorizedRootSessionId: $authorizedRootSessionId,
            parentRunId: (string) $body->nativeParentRunId,
            artifactId: (string) $body->nativeArtifactId,
            handoffId: (string) $body->nativeHandoffId,
            expectedBytes: $body->bytes,
            expectedSha256: $body->sha256,
            expectedMediaType: $body->mediaType,
            maxDecodeBytes: $budget,
        );

        $result = [
            'kind' => $body->kind->value,
            'media_type' => $read['media_type'],
            'bytes' => $read['bytes'],
            'sha256' => $read['sha256'],
            'truncated' => $read['truncated'],
        ];
        if (isset($read['text']) && \is_string($read['text'])) {
            $result['text'] = $read['text'];
        }

        return $result;
    }
}
