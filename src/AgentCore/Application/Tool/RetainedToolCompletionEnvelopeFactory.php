<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeHandoffBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedOutputCapBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionIntegrity;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionOrigin;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionPresentation;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionUtf8;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;
use Psr\Log\LoggerInterface;

/**
 * Builds immutable retained tool_completion envelopes at owner acceptance.
 *
 * Prefers host-authored immutable native handoff generations. Otherwise keeps
 * small validated bodies inline or streams oversized bodies into owned session
 * storage before native/output-cap cleanup may reclaim temps.
 */
final readonly class RetainedToolCompletionEnvelopeFactory
{
    public function __construct(
        private RetainedToolCompletionBodyStoreInterface $bodyStore,
        private RetainedToolCompletionEnvelopeCodec $codec,
        private OwningRootSessionResolverInterface $owningRootSessionResolver,
        private ?RetainedOutputCapBodyPromoterInterface $outputCapPromoter = null,
        private ?RetainedNativeHandoffBodyPromoterInterface $nativeHandoffPromoter = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    public function build(
        string $completionId,
        DeferredToolCompletionCorrelation $correlation,
        ToolCallResult $result,
        array $normalizedToolResult,
        string $executionOwnerKind,
        ?string $controllerRunId = null,
    ): RetainedToolCompletionEnvelope {
        if (!ToolResultText::isValid($normalizedToolResult)) {
            throw new \InvalidArgumentException('Retained completion requires a validated terminal tool_result.');
        }

        // Canonical ToolExecutionEnd payload nests the worker outcome under tool_result.result.
        // Outer identity/fingerprint stay on the full MESSAGE representation; body promotion
        // and presentation read the nested outcome when present.
        $outcome = $this->outcomePayload($normalizedToolResult);

        $owningRoot = $this->resolveOwningRoot($correlation, $controllerRunId);
        $terminalOutcome = $this->terminalOutcome($result, $outcome, $normalizedToolResult);
        $toolName = \is_string($outcome['tool_name'] ?? null) && '' !== $outcome['tool_name']
            ? $outcome['tool_name']
            : $correlation->toolName;
        $subject = RetainedToolCompletionUtf8::truncate(
            \sprintf('%s %s', $toolName, $terminalOutcome),
            RetainedToolCompletionBounds::SUBJECT_MAX_BYTES,
        );
        $summary = RetainedToolCompletionUtf8::truncate(
            $this->boundedSummary($outcome, $terminalOutcome, $toolName),
            RetainedToolCompletionBounds::SUMMARY_MAX_BYTES,
        );

        $attachments = [];
        $nativeGenerations = $this->hostAuthoredNativeGenerations($outcome);
        if ([] !== $nativeGenerations) {
            $body = $this->bodyForNativeGenerations($completionId, $owningRoot, $nativeGenerations);
            $attachments = $this->boundedNativeAttachments($nativeGenerations);
        } else {
            $promoted = null;
            if (null !== $this->outputCapPromoter) {
                $promoted = $this->outputCapPromoter->promoteIfOwned(
                    owningRootSessionId: $owningRoot,
                    messageId: $completionId,
                    normalizedToolResult: $outcome,
                    sourceRunId: $correlation->runId,
                );
            }
            $body = $promoted ?? $this->inlineOrOwnedBody(
                $completionId,
                $owningRoot,
                $outcome,
            );
        }

        $origin = new RetainedToolCompletionOrigin(
            controllerRunId: $owningRoot,
            sourceRunId: $correlation->runId,
            originTurnNo: $correlation->turnNo,
            originStepId: $correlation->stepId,
            toolCallId: $correlation->toolCallId,
            invocationId: $correlation->invocationId ?? $correlation->deferredId,
            executionOwnerKind: $executionOwnerKind,
        );
        $presentation = new RetainedToolCompletionPresentation(
            severity: 'info',
            terminalOutcome: $terminalOutcome,
            subject: $subject,
            boundedSummary: $summary,
        );

        $draft = new RetainedToolCompletionEnvelope(
            messageId: $completionId,
            origin: $origin,
            presentation: $presentation,
            body: $body,
            integrity: new RetainedToolCompletionIntegrity(str_repeat('0', 64)),
            attachments: $attachments,
        );
        $fingerprint = $this->codec->payloadFingerprint($draft);
        $envelope = new RetainedToolCompletionEnvelope(
            messageId: $completionId,
            origin: $origin,
            presentation: $presentation,
            body: $body,
            integrity: new RetainedToolCompletionIntegrity($fingerprint),
            attachments: $attachments,
        );

        $serialized = json_encode($this->codec->normalize($envelope), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        if (\strlen($serialized) > RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES) {
            // Prefer a compact attachment list before failing a completed batch permanently.
            if (\count($attachments) > 1) {
                $attachments = \array_slice($attachments, 0, 1);
                $draft = new RetainedToolCompletionEnvelope(
                    messageId: $completionId,
                    origin: $origin,
                    presentation: $presentation,
                    body: $body,
                    integrity: new RetainedToolCompletionIntegrity(str_repeat('0', 64)),
                    attachments: $attachments,
                );
                $fingerprint = $this->codec->payloadFingerprint($draft);
                $envelope = new RetainedToolCompletionEnvelope(
                    messageId: $completionId,
                    origin: $origin,
                    presentation: $presentation,
                    body: $body,
                    integrity: new RetainedToolCompletionIntegrity($fingerprint),
                    attachments: $attachments,
                );
                $serialized = json_encode($this->codec->normalize($envelope), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            }
            if (\strlen($serialized) > RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES) {
                throw new \InvalidArgumentException('Retained envelope exceeds 16 KiB budget.');
            }
        }

        return $envelope;
    }

    private function resolveOwningRoot(
        DeferredToolCompletionCorrelation $correlation,
        ?string $controllerRunId,
    ): string {
        $resolved = $this->owningRootSessionResolver->resolveOwningRootSessionId($correlation->runId);
        if (null !== $correlation->controllerRunId && $correlation->controllerRunId !== $resolved) {
            throw new \InvalidArgumentException(\sprintf('Persisted controllerRunId "%s" conflicts with owning root "%s" for source run "%s".', $correlation->controllerRunId, $resolved, $correlation->runId));
        }
        if (null !== $controllerRunId && '' !== trim($controllerRunId) && $controllerRunId !== $resolved) {
            throw new \InvalidArgumentException(\sprintf('Caller controllerRunId "%s" conflicts with owning root "%s" for source run "%s".', $controllerRunId, $resolved, $correlation->runId));
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     *
     * @return list<RetainedNativeHandoffGeneration>
     */
    private function hostAuthoredNativeGenerations(array $normalizedToolResult): array
    {
        $details = $normalizedToolResult['details'] ?? null;
        if (!\is_array($details)) {
            return [];
        }

        $rawList = $details['native_artifact_handoffs'] ?? null;
        if (!\is_array($rawList) || [] === $rawList) {
            // Single-child host fields written by DeferredSubagentBatchCompletionDispatcher.
            $single = $this->tryNativeGeneration($details);

            return null === $single ? [] : [$single];
        }

        $generations = [];
        foreach ($rawList as $entry) {
            if (!\is_array($entry)) {
                return [];
            }
            $generation = $this->tryNativeGeneration($entry);
            if (null === $generation) {
                // Incomplete host metadata is ignored as a whole; do not invent generations.
                return [];
            }
            $generations[] = $generation;
        }

        return $generations;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tryNativeGeneration(array $payload): ?RetainedNativeHandoffGeneration
    {
        $artifactId = $payload['artifact_id'] ?? null;
        $handoffId = $payload['handoff_id'] ?? null;
        $parentRunId = $payload['parent_run_id'] ?? null;
        $bytes = $payload['handoff_bytes'] ?? ($payload['bytes'] ?? null);
        $sha256 = $payload['handoff_sha256'] ?? ($payload['sha256'] ?? null);
        $mediaType = $payload['handoff_media_type'] ?? ($payload['media_type'] ?? 'text/markdown');

        if (!\is_string($artifactId) || '' === trim($artifactId)
            || !\is_string($handoffId) || '' === trim($handoffId)
            || !\is_string($parentRunId) || '' === trim($parentRunId)
            || !\is_int($bytes) || $bytes < 0
            || !\is_string($sha256) || 64 !== \strlen($sha256) || 1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)
            || !\is_string($mediaType) || '' === trim($mediaType)) {
            return null;
        }

        try {
            return new RetainedNativeHandoffGeneration(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                handoffId: $handoffId,
                bytes: $bytes,
                sha256: $sha256,
                mediaType: $mediaType,
            );
        } catch (\InvalidArgumentException $exception) {
            // Intentional local degradation: incomplete host metadata must not invent a generation.
            $this->logger?->warning('retained_completion.native_generation_rejected', [
                'component' => 'tool.retained_completion',
                'event_type' => 'retained_completion.native_generation_rejected',
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param list<RetainedNativeHandoffGeneration> $generations
     *
     * @return list<array{kind: string, reference: array<string, mixed>}>
     */
    private function boundedNativeAttachments(array $generations): array
    {
        $attachments = [];
        $limit = min(\count($generations), RetainedToolCompletionBounds::INLINE_NATIVE_ATTACHMENT_MAX);
        for ($i = 0; $i < $limit; ++$i) {
            $generation = $generations[$i];
            $attachments[] = [
                'kind' => 'native_artifact_handoff',
                'reference' => [
                    'parent_run_id' => $generation->parentRunId,
                    'artifact_id' => $generation->artifactId,
                    'handoff_id' => $generation->handoffId,
                    'bytes' => $generation->bytes,
                    'sha256' => $generation->sha256,
                    'media_type' => $generation->mediaType,
                ],
            ];
        }

        return $attachments;
    }

    /**
     * @param list<RetainedNativeHandoffGeneration> $generations
     */
    private function bodyForNativeGenerations(
        string $completionId,
        string $owningRootSessionId,
        array $generations,
    ): RetainedToolCompletionBodyReference {
        if (1 === \count($generations)) {
            $generation = $generations[0];
            if ($generation->parentRunId === $owningRootSessionId) {
                return RetainedToolCompletionBodyReference::fromNativeGeneration($generation);
            }
            if (null === $this->nativeHandoffPromoter) {
                throw new \InvalidArgumentException(\sprintf('Native handoff parent "%s" is outside owning root "%s" and no promoter is configured.', $generation->parentRunId, $owningRootSessionId));
            }

            // UUID child parents store physical handoffs outside the root session tree.
            // Seal a root-owned copy so authorized root deletion reclaims the promised body.
            return $this->nativeHandoffPromoter->sealOwnedCopy(
                owningRootSessionId: $owningRootSessionId,
                messageId: $completionId,
                generation: $generation,
            );
        }

        return $this->sealNativeManifest($completionId, $owningRootSessionId, $generations);
    }

    /**
     * @param list<RetainedNativeHandoffGeneration> $generations
     */
    private function sealNativeManifest(
        string $completionId,
        string $owningRootSessionId,
        array $generations,
    ): RetainedToolCompletionBodyReference {
        $manifest = [
            'kind' => 'native_artifact_handoff_manifest',
            'count' => \count($generations),
            'entries' => array_map(
                static fn (RetainedNativeHandoffGeneration $generation): array => [
                    'parent_run_id' => $generation->parentRunId,
                    'artifact_id' => $generation->artifactId,
                    'handoff_id' => $generation->handoffId,
                    'bytes' => $generation->bytes,
                    'sha256' => $generation->sha256,
                    'media_type' => $generation->mediaType,
                ],
                $generations,
            ),
        ];
        $encoded = json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $stream = fopen('php://temp', 'r+b');
        if (false === $stream) {
            throw new \RuntimeException('Unable to allocate temporary stream for native handoff manifest.');
        }
        try {
            fwrite($stream, $encoded);
            rewind($stream);

            return $this->bodyStore->sealJsonStream(
                owningRootSessionId: $owningRootSessionId,
                messageId: $completionId,
                jsonStream: $stream,
                mediaType: RetainedToolCompletionBounds::MEDIA_TYPE_JSON,
                expectedBytes: \strlen($encoded),
                expectedSha256: hash('sha256', $encoded),
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    private function inlineOrOwnedBody(
        string $completionId,
        string $owningRootSessionId,
        array $normalizedToolResult,
    ): RetainedToolCompletionBodyReference {
        // The accepted tool_result is already resident in the receipt payload.
        // Prefer an inline body under the budget. For oversized bodies, stream the
        // already-encoded JSON once into owned storage without keeping extra
        // substr copies beyond the single encoded buffer required for hashing.
        $encoded = json_encode($normalizedToolResult, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $bytes = \strlen($encoded);
        $sha256 = hash('sha256', $encoded);

        if ($bytes <= RetainedToolCompletionBounds::INLINE_BODY_MAX_BYTES) {
            return RetainedToolCompletionBodyReference::inline(
                inline: $normalizedToolResult,
                mediaType: RetainedToolCompletionBounds::MEDIA_TYPE_JSON,
                bytes: $bytes,
                sha256: $sha256,
            );
        }

        $stream = fopen('php://temp', 'r+b');
        if (false === $stream) {
            throw new \RuntimeException('Unable to allocate temporary stream for retained completion body.');
        }
        try {
            // One write of the already-encoded resident JSON; no chunked substr copies.
            $written = fwrite($stream, $encoded);
            if (false === $written || $written !== $bytes) {
                throw new \RuntimeException('Failed to buffer retained completion body for sealing.');
            }
            unset($encoded);
            rewind($stream);

            return $this->bodyStore->sealJsonStream(
                owningRootSessionId: $owningRootSessionId,
                messageId: $completionId,
                jsonStream: $stream,
                mediaType: RetainedToolCompletionBounds::MEDIA_TYPE_JSON,
                expectedBytes: $bytes,
                expectedSha256: $sha256,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     *
     * @return array<string, mixed>
     */
    private function outcomePayload(array $normalizedToolResult): array
    {
        $nested = $normalizedToolResult['result'] ?? null;
        if (\is_array($nested) && (
            \array_key_exists('content', $nested)
            || \array_key_exists('details', $nested)
            || \array_key_exists('tool_name', $nested)
            || \array_key_exists('is_error', $nested)
        )) {
            return $nested;
        }

        return $normalizedToolResult;
    }

    /**
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $normalizedToolResult
     */
    private function terminalOutcome(ToolCallResult $result, array $outcome, array $normalizedToolResult): string
    {
        $isError = $result->isError
            || true === ($outcome['is_error'] ?? false)
            || true === ($normalizedToolResult['is_error'] ?? false);
        if ($isError) {
            $details = $outcome['details'] ?? ($normalizedToolResult['details'] ?? null);
            if (\is_array($details) && true === ($details['cancelled'] ?? false)) {
                return 'cancelled';
            }

            return 'failed';
        }

        return 'completed';
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    private function boundedSummary(array $normalizedToolResult, string $terminalOutcome, string $toolName): string
    {
        $parts = [\sprintf('%s %s.', $toolName, $terminalOutcome)];
        $content = $normalizedToolResult['content'] ?? null;
        if (\is_array($content)) {
            foreach ($content as $part) {
                if (!\is_array($part) || ($part['type'] ?? null) !== 'text') {
                    continue;
                }
                $text = $part['text'] ?? null;
                if (\is_string($text) && '' !== trim($text)) {
                    $parts[] = $text;
                    break;
                }
            }
        }

        $details = $normalizedToolResult['details'] ?? null;
        if (\is_array($details)) {
            foreach (['error_type', 'hint'] as $key) {
                $value = $details[$key] ?? null;
                if (\is_string($value) && '' !== trim($value)) {
                    $parts[] = $key.': '.$value;
                }
            }
            if (isset($details['artifact_id']) && \is_string($details['artifact_id']) && '' !== trim($details['artifact_id'])) {
                $parts[] = 'artifact_id: '.$details['artifact_id'];
            }
            $nativeCount = $details['native_artifact_handoffs'] ?? null;
            if (\is_array($nativeCount) && \count($nativeCount) > 1) {
                $parts[] = 'native_handoffs: '.\count($nativeCount);
            }
        }

        return implode(' ', $parts);
    }
}
