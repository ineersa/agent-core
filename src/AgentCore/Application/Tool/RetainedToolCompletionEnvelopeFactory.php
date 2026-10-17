<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionOrigin;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionUtf8;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;

/**
 * Builds immutable retained tool_completion envelopes at owner acceptance.
 *
 * Prefers existing native immutable handoff generations when present. Otherwise
 * keeps small validated bodies inline or seals oversized bodies into owned
 * session storage before native/output-cap cleanup may reclaim temps.
 */
final readonly class RetainedToolCompletionEnvelopeFactory
{
    public function __construct(
        private ?RetainedToolCompletionBodyStoreInterface $bodyStore = null,
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
        string $controllerRunId,
        string $executionOwnerKind,
    ): RetainedToolCompletionEnvelope {
        if (!ToolResultText::isValid($normalizedToolResult)) {
            throw new \InvalidArgumentException('Retained completion requires a validated terminal tool_result.');
        }

        $terminalOutcome = $this->terminalOutcome($result, $normalizedToolResult);
        $toolName = \is_string($normalizedToolResult['tool_name'] ?? null) && '' !== $normalizedToolResult['tool_name']
            ? $normalizedToolResult['tool_name']
            : $correlation->toolName;
        $subject = RetainedToolCompletionUtf8::truncate(
            \sprintf('%s %s', $toolName, $terminalOutcome),
            RetainedToolCompletionBounds::SUBJECT_MAX_BYTES,
        );
        $summary = RetainedToolCompletionUtf8::truncate(
            $this->boundedSummary($normalizedToolResult, $terminalOutcome, $toolName),
            RetainedToolCompletionBounds::SUMMARY_MAX_BYTES,
        );

        $attachments = [];
        $native = $this->nativeArtifactHandoffReference($normalizedToolResult);
        if (null !== $native) {
            $body = $native['body'];
            $attachments[] = $native['attachment'];
        } else {
            $body = $this->inlineOrOwnedBody(
                $completionId,
                $controllerRunId,
                $normalizedToolResult,
            );
        }

        $origin = new RetainedToolCompletionOrigin(
            controllerRunId: $controllerRunId,
            sourceRunId: $correlation->runId,
            originTurnNo: $correlation->turnNo,
            originStepId: $correlation->stepId,
            toolCallId: $correlation->toolCallId,
            invocationId: $correlation->invocationId ?? $correlation->deferredId,
            executionOwnerKind: $executionOwnerKind,
        );

        $draft = new RetainedToolCompletionEnvelope(
            messageId: $completionId,
            origin: $origin,
            terminalOutcome: $terminalOutcome,
            subject: $subject,
            boundedSummary: $summary,
            body: $body,
            payloadFingerprint: str_repeat('0', 64),
            attachments: $attachments,
        );
        $fingerprint = hash(
            'sha256',
            json_encode($draft->semanticFingerprintPayload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );

        return new RetainedToolCompletionEnvelope(
            messageId: $completionId,
            origin: $origin,
            terminalOutcome: $terminalOutcome,
            subject: $subject,
            boundedSummary: $summary,
            body: $body,
            payloadFingerprint: $fingerprint,
            attachments: $attachments,
        );
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     *
     * @return array{body: RetainedToolCompletionBodyReference, attachment: array{kind: string, reference: array<string, mixed>}}|null
     */
    private function nativeArtifactHandoffReference(array $normalizedToolResult): ?array
    {
        $details = $normalizedToolResult['details'] ?? null;
        if (!\is_array($details)) {
            return null;
        }

        $artifactId = $details['artifact_id'] ?? null;
        $handoffId = $details['handoff_id'] ?? null;
        $parentRunId = $details['parent_run_id'] ?? ($details['artifact_parent_run_id'] ?? null);
        $bytes = $details['handoff_bytes'] ?? null;
        $sha256 = $details['handoff_sha256'] ?? null;
        $mediaType = $details['handoff_media_type'] ?? 'text/markdown';

        if (!\is_string($artifactId) || '' === trim($artifactId)
            || !\is_string($handoffId) || '' === trim($handoffId)
            || !\is_string($parentRunId) || '' === trim($parentRunId)
            || !\is_int($bytes) || $bytes < 0
            || !\is_string($sha256) || 64 !== \strlen($sha256) || 1 !== preg_match('/^[a-f0-9]{64}$/', $sha256)
            || !\is_string($mediaType) || '' === trim($mediaType)) {
            // Incomplete native metadata is ignored; do not invent generations or
            // scrape presentation text for paths/IDs.
            return null;
        }

        $body = RetainedToolCompletionBodyReference::nativeArtifactHandoff(
            nativeParentRunId: $parentRunId,
            nativeArtifactId: $artifactId,
            nativeHandoffId: $handoffId,
            mediaType: $mediaType,
            bytes: $bytes,
            sha256: $sha256,
        );

        return [
            'body' => $body,
            'attachment' => [
                'kind' => 'native_artifact_handoff',
                'reference' => [
                    'parent_run_id' => $parentRunId,
                    'artifact_id' => $artifactId,
                    'handoff_id' => $handoffId,
                    'bytes' => $bytes,
                    'sha256' => $sha256,
                    'media_type' => $mediaType,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    private function inlineOrOwnedBody(
        string $completionId,
        string $controllerRunId,
        array $normalizedToolResult,
    ): RetainedToolCompletionBodyReference {
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

        if (null === $this->bodyStore) {
            throw new \LogicException('Large retained completion bodies require a sealed body store.');
        }

        return $this->bodyStore->sealJson(
            owningRootSessionId: $controllerRunId,
            messageId: $completionId,
            json: $encoded,
            mediaType: RetainedToolCompletionBounds::MEDIA_TYPE_JSON,
        );
    }

    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    private function terminalOutcome(ToolCallResult $result, array $normalizedToolResult): string
    {
        if ($result->isError || true === ($normalizedToolResult['is_error'] ?? false)) {
            $details = $normalizedToolResult['details'] ?? null;
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
        }

        return implode(' ', $parts);
    }
}
