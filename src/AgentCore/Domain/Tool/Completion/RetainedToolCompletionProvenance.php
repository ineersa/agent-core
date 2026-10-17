<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Runtime provenance for one retained tool_completion message. */
final readonly class RetainedToolCompletionProvenance
{
    public function __construct(
        public string $producer = 'runtime',
        public string $payloadAuthority = 'external_tool_result',
    ) {
        if ('' === trim($this->producer)) {
            throw new \InvalidArgumentException('Retained provenance producer must be nonblank.');
        }
        if ('' === trim($this->payloadAuthority)) {
            throw new \InvalidArgumentException('Retained provenance payloadAuthority must be nonblank.');
        }
    }
}
