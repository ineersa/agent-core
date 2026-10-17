<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\RetainedNativeArtifactHandoffReaderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;

/**
 * Resolves immutable handoff generations for retained completion evidence.
 *
 * Authorization is the owning root session. Foreign parents and missing or
 * mutated generations fail closed without executing tools.
 */
final readonly class RetainedNativeArtifactHandoffReader implements RetainedNativeArtifactHandoffReaderInterface
{
    public function __construct(
        private AgentArtifactRegistry $artifacts,
    ) {
    }

    public function readHandoff(
        string $authorizedRootSessionId,
        string $parentRunId,
        string $artifactId,
        string $handoffId,
        int $expectedBytes,
        string $expectedSha256,
        string $expectedMediaType,
    ): array {
        if ('' === trim($authorizedRootSessionId) || '' === trim($parentRunId)
            || '' === trim($artifactId) || '' === trim($handoffId)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff identity is incomplete.');
        }
        if ($parentRunId !== $authorizedRootSessionId) {
            // Child artifacts live under the parent/root session. Foreign parents
            // are rejected rather than walking arbitrary registries.
            $owned = false;
            foreach ($this->artifacts->list($authorizedRootSessionId) as $entry) {
                if ($entry->artifactId === $artifactId && $entry->parentRunId === $parentRunId) {
                    $owned = true;
                    break;
                }
            }
            if (!$owned) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff is outside the authorized root session.');
            }
        }

        try {
            $text = $this->artifacts->readHandoffHistoryEntry($parentRunId, $artifactId, $handoffId);
        } catch (\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Native handoff generation is missing.', $exception);
        } catch (\Throwable $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Native handoff generation is unreadable.', $exception);
        }

        $bytes = \strlen($text);
        $sha256 = hash('sha256', $text);
        if ($bytes !== $expectedBytes || $sha256 !== $expectedSha256) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Native handoff generation failed integrity validation.');
        }

        return [
            'bytes' => $bytes,
            'sha256' => $sha256,
            'media_type' => $expectedMediaType,
            'text' => $text,
        ];
    }
}
