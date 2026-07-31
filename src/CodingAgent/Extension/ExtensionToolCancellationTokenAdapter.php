<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;

/**
 * Bridges AgentCore's internal cancellation token to the public ExtensionApi token.
 *
 * Lives in AppExtension — the only place allowed to depend on both AgentCore
 * cancellation contracts and ExtensionApi tool types.
 */
final readonly class ExtensionToolCancellationTokenAdapter implements ToolCancellationTokenInterface
{
    public function __construct(
        private CancellationTokenInterface $inner,
    ) {
    }

    public function isCancellationRequested(): bool
    {
        return $this->inner->isCancellationRequested();
    }
}
