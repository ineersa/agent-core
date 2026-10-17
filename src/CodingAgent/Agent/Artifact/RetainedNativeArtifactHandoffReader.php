<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\RetainedNativeArtifactHandoffReaderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
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
        private OwningRootSessionResolver $owningRoots,
    ) {
    }

    public function inspectHandoff(
        string $authorizedRootSessionId,
        string $parentRunId,
        string $artifactId,
        string $handoffId,
        int $expectedBytes,
        string $expectedSha256,
        string $expectedMediaType,
        ?int $maxDecodeBytes = null,
    ): array {
        if ('' === trim($authorizedRootSessionId) || '' === trim($parentRunId)
            || '' === trim($artifactId) || '' === trim($handoffId)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff identity is incomplete.');
        }

        try {
            $resolvedRoot = $this->owningRoots->resolveOwningRootSessionId($parentRunId);
        } catch (\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff parent identity is invalid.', $exception);
        }
        if ($resolvedRoot !== $authorizedRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff is outside the authorized root session.');
        }

        $entry = $this->artifacts->get($parentRunId, $artifactId);
        if (null === $entry) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff artifact is outside the authorized root session.');
        }

        $budget = $maxDecodeBytes ?? RetainedToolCompletionBounds::INSPECTION_BODY_MAX_BYTES;
        if ($budget < 0) {
            throw new \InvalidArgumentException('maxDecodeBytes must be non-negative.');
        }

        try {
            return $this->artifacts->inspectHandoffHistoryEntry(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                handoffId: $handoffId,
                expectedBytes: $expectedBytes,
                expectedSha256: $expectedSha256,
                expectedMediaType: $expectedMediaType,
                maxDecodeBytes: $budget,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Native handoff generation is missing.', $exception);
        } catch (\Throwable $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Native handoff generation is unreadable.', $exception);
        }
    }
}
