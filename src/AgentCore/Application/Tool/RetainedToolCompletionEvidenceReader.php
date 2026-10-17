<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeArtifactHandoffReaderInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
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
        private ?RetainedToolCompletionBodyStoreInterface $bodyStore = null,
        private ?RetainedNativeArtifactHandoffReaderInterface $nativeHandoffs = null,
    ) {
    }

    public function read(string $authorizedRootSessionId, string $sourceRunId, string $messageId): array
    {
        if ('' === trim($authorizedRootSessionId) || '' === trim($sourceRunId) || '' === trim($messageId)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion lookup identity is incomplete.');
        }
        if (false !== strpbrk($authorizedRootSessionId, '/\\')
            || false !== strpbrk($sourceRunId, '/\\')
            || false !== strpbrk($messageId, '/\\')) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion lookup rejected path-like identity.');
        }

        $envelope = null;
        foreach ($this->events->rangeFor($sourceRunId, 1, \PHP_INT_MAX) as $event) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $event->type) {
                continue;
            }
            $retained = $event->payload['accepted_completion']['retained_message'] ?? null;
            if (!\is_array($retained)) {
                continue;
            }
            $candidateId = $retained['message_id'] ?? null;
            if ($candidateId !== $messageId) {
                continue;
            }

            try {
                $decoded = RetainedToolCompletionEnvelope::fromArray($retained);
            } catch (RetainedToolCompletionFailure $failure) {
                throw $failure;
            } catch (\Throwable $exception) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope is corrupt.', $exception);
            }

            if ($decoded->origin->controllerRunId !== $authorizedRootSessionId) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion controller scope does not match the authorized root session.');
            }
            if ($decoded->origin->sourceRunId !== $sourceRunId) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Retained completion source run does not match the lookup run.');
            }

            $expectedFingerprint = hash(
                'sha256',
                json_encode($decoded->semanticFingerprintPayload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            );
            if ($expectedFingerprint !== $decoded->payloadFingerprint) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope failed semantic fingerprint validation.');
            }

            $envelope = $decoded;
            break;
        }

        if (null === $envelope) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::NotFound, \sprintf('Retained completion "%s" was not found for run "%s".', $messageId, $sourceRunId));
        }

        return [
            'envelope' => $envelope,
            'body' => $this->resolveBody($envelope, $authorizedRootSessionId),
        ];
    }

    /**
     * @return array{
     *     kind: string,
     *     media_type: string,
     *     bytes: int,
     *     sha256: string,
     *     inline?: array<string, mixed>,
     *     json?: array<string, mixed>,
     *     text?: string
     * }
     */
    private function resolveBody(RetainedToolCompletionEnvelope $envelope, string $authorizedRootSessionId): array
    {
        $body = $envelope->body;

        return match ($body->kind) {
            RetainedToolCompletionBodyKindEnum::Inline => [
                'kind' => $body->kind->value,
                'media_type' => $body->mediaType,
                'bytes' => $body->bytes,
                'sha256' => $body->sha256,
                'inline' => $body->inline ?? [],
            ],
            RetainedToolCompletionBodyKindEnum::OwnedSealed => $this->resolveOwnedSealed($envelope, $authorizedRootSessionId),
            RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff => $this->resolveNative($envelope, $authorizedRootSessionId),
        };
    }

    /**
     * @return array{kind: string, media_type: string, bytes: int, sha256: string, json: array<string, mixed>}
     */
    private function resolveOwnedSealed(RetainedToolCompletionEnvelope $envelope, string $authorizedRootSessionId): array
    {
        if (null === $this->bodyStore) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Owned sealed retained bodies are unavailable.');
        }
        $read = $this->bodyStore->readJson($envelope->body, $authorizedRootSessionId);

        return [
            'kind' => $envelope->body->kind->value,
            'media_type' => $read['media_type'],
            'bytes' => $read['bytes'],
            'sha256' => $read['sha256'],
            'json' => $read['json'],
        ];
    }

    /**
     * @return array{kind: string, media_type: string, bytes: int, sha256: string, text: string}
     */
    private function resolveNative(RetainedToolCompletionEnvelope $envelope, string $authorizedRootSessionId): array
    {
        if (null === $this->nativeHandoffs) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Native artifact handoff retained bodies are unavailable.');
        }
        $body = $envelope->body;
        $read = $this->nativeHandoffs->readHandoff(
            authorizedRootSessionId: $authorizedRootSessionId,
            parentRunId: (string) $body->nativeParentRunId,
            artifactId: (string) $body->nativeArtifactId,
            handoffId: (string) $body->nativeHandoffId,
            expectedBytes: $body->bytes,
            expectedSha256: $body->sha256,
            expectedMediaType: $body->mediaType,
        );

        return [
            'kind' => $body->kind->value,
            'media_type' => $read['media_type'],
            'bytes' => $read['bytes'],
            'sha256' => $read['sha256'],
            'text' => $read['text'],
        ];
    }
}
