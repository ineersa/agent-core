<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Integrity digests for one retained tool_completion message. */
final readonly class RetainedToolCompletionIntegrity
{
    public function __construct(
        public string $payloadFingerprint,
    ) {
        if (64 !== \strlen($this->payloadFingerprint) || 1 !== preg_match('/^[a-f0-9]{64}$/', $this->payloadFingerprint)) {
            throw new \InvalidArgumentException('Retained envelope payload fingerprint must be a lowercase hex digest.');
        }
    }
}
