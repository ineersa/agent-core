<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Small typed native-owner reference. Identity is kind + immutable record ID.
 * PID and log path are presentation fields only and never authorize lookup alone.
 */
final readonly class ToolInvocationOwnerReference
{
    public function __construct(
        public string $kind,
        public string $recordId,
        public ?int $presentationPid = null,
        public ?string $presentationLogPath = null,
    ) {
        if ('' === $this->kind) {
            throw new \InvalidArgumentException('Tool invocation owner kind is required.');
        }
        if ('' === $this->recordId) {
            throw new \InvalidArgumentException('Tool invocation owner record ID is required.');
        }
        if (null !== $this->presentationPid && $this->presentationPid <= 0) {
            throw new \InvalidArgumentException('Tool invocation presentation PID must be positive when present.');
        }
    }
}
