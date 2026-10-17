<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeHandoffBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionBodyStoreInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;

/**
 * Streams an immutable native handoff generation into root-owned sealed storage.
 *
 * Does not invent a new artifact layout. Physical handoffs remain under their
 * parent run directories; the sealed copy is the C4 body promised to the root.
 * Authorization is the owning root resolved from the generation parent, not the
 * host-authored details alone.
 */
final readonly class RetainedNativeHandoffBodyPromoter implements RetainedNativeHandoffBodyPromoterInterface
{
    public function __construct(
        private SessionAgentArtifactPathResolver $paths,
        private RetainedToolCompletionBodyStoreInterface $bodyStore,
        private OwningRootSessionResolverInterface $owningRoots,
    ) {
    }

    public function sealOwnedCopy(
        string $owningRootSessionId,
        string $messageId,
        RetainedNativeHandoffGeneration $generation,
    ): RetainedToolCompletionBodyReference {
        try {
            $resolvedRoot = $this->owningRoots->resolveOwningRootSessionId($generation->parentRunId);
        } catch (\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff parent identity is invalid for root-owned seal.', $exception);
        }
        if ($resolvedRoot !== $owningRootSessionId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Native handoff is outside the authorized root session for root-owned seal.');
        }

        $paths = AgentArtifactPathsDTO::forArtifactId($generation->artifactId);
        $relative = "{$paths->artifactDir}/handoffs/{$generation->handoffId}.md";
        $absolute = $this->paths->absolutePath($generation->parentRunId, $relative);
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyMissing, 'Native handoff generation is missing for root-owned seal.');
        }

        $stream = fopen($absolute, 'rb');
        if (false === $stream) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Native handoff generation is unreadable for root-owned seal.');
        }
        try {
            return $this->bodyStore->sealJsonStream(
                owningRootSessionId: $owningRootSessionId,
                messageId: $messageId,
                jsonStream: $stream,
                mediaType: $generation->mediaType,
                expectedBytes: $generation->bytes,
                expectedSha256: $generation->sha256,
            );
        } finally {
            fclose($stream);
        }
    }
}
