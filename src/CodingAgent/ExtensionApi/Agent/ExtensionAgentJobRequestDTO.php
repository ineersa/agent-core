<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

/**
 * Public, serializable request for one asynchronous extension-agent job.
 *
 * Payload must be JSON-safe (scalars, lists, associative arrays). Live objects,
 * closures, and ExtensionToolHandlerInterface instances are forbidden.
 */
final readonly class ExtensionAgentJobRequestDTO
{
    /**
     * @param string               $handlerId     stable extension-owned handler id (e.g. observational_memory.observe_boundary)
     * @param array<string, mixed> $payload       JSON-safe job payload
     * @param string|null          $jobId         optional deterministic job identity for diagnostics/idempotency
     * @param string|null          $correlationId optional correlation token for structured logs
     */
    public function __construct(
        public string $handlerId,
        public array $payload = [],
        public ?string $jobId = null,
        public ?string $correlationId = null,
    ) {
        $handlerId = trim($this->handlerId);
        if ('' === $handlerId) {
            throw new \InvalidArgumentException('Extension agent job handlerId must be a non-empty string.');
        }

        if (null !== $this->jobId && '' === trim($this->jobId)) {
            throw new \InvalidArgumentException('Extension agent job jobId must be null or a non-empty string.');
        }

        if (null !== $this->correlationId && '' === trim($this->correlationId)) {
            throw new \InvalidArgumentException('Extension agent job correlationId must be null or a non-empty string.');
        }

        $this->assertJsonSafe($this->payload, 'payload');
    }

    private function assertJsonSafe(mixed $value, string $path): void
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return;
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('Extension agent job %s must be JSON-safe; got %s.', $path, get_debug_type($value)));
        }

        foreach ($value as $key => $child) {
            // PHP array keys are only int|string; keep the cast for path labels.
            $childPath = $path.'['.(string) $key.']';
            $this->assertJsonSafe($child, $childPath);
        }
    }
}
