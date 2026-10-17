<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;

/**
 * Seals a host-authored native handoff generation under the owning root session.
 *
 * Used when the physical handoff path is scoped under a non-root parent run id
 * (for example a UUID child directory) so root deletion can reclaim the promised
 * retained body without inventing a phantom child session tree.
 */
interface RetainedNativeHandoffBodyPromoterInterface
{
    public function sealOwnedCopy(
        string $owningRootSessionId,
        string $messageId,
        RetainedNativeHandoffGeneration $generation,
    ): RetainedToolCompletionBodyReference;
}
