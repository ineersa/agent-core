<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Exact top-level readiness binding for a background acknowledgment request.
 *
 * Eligibility still requires a later registered execution-owner adapter. This
 * value only carries the immutable identity and native owner reference that C2
 * validates before preparing wait resolution. It does not expose capability,
 * start execution, or resolve the caller by itself.
 */
final readonly class ToolInvocationBackgroundReadiness
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public string $toolCallId,
        public string $toolName,
        public ToolInvocationOwnerReference $ownerReference,
        public ?string $invocationId = null,
        public ?string $controllerRunId = null,
        public ?string $envelopeFingerprint = null,
    ) {
        if ('' === $this->runId || '' === $this->stepId || '' === $this->toolCallId || '' === $this->toolName) {
            throw new \InvalidArgumentException('Background readiness requires a complete invocation identity.');
        }
        if ($this->turnNo < 0) {
            throw new \InvalidArgumentException('Background readiness turn number cannot be negative.');
        }
        if (null !== $this->invocationId && '' === $this->invocationId) {
            throw new \InvalidArgumentException('Background readiness invocation alias cannot be empty when present.');
        }
        if (null !== $this->controllerRunId && '' === $this->controllerRunId) {
            throw new \InvalidArgumentException('Background readiness controller run cannot be empty when present.');
        }
        if (null !== $this->envelopeFingerprint && '' === $this->envelopeFingerprint) {
            throw new \InvalidArgumentException('Background readiness envelope fingerprint cannot be empty when present.');
        }
    }
}
