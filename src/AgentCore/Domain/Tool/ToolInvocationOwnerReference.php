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

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $kind = $payload['kind'] ?? null;
        $recordId = $payload['record_id'] ?? null;
        if (!\is_string($kind) || !\is_string($recordId)) {
            throw new \InvalidArgumentException('Tool invocation owner reference requires kind and record_id.');
        }
        $pid = $payload['presentation_pid'] ?? null;
        $logPath = $payload['presentation_log_path'] ?? null;

        return new self(
            kind: $kind,
            recordId: $recordId,
            presentationPid: null === $pid ? null : (int) $pid,
            presentationLogPath: null === $logPath ? null : (string) $logPath,
        );
    }

    /**
     * @return array{kind: string, record_id: string, presentation_pid: ?int, presentation_log_path: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'record_id' => $this->recordId,
            'presentation_pid' => $this->presentationPid,
            'presentation_log_path' => $this->presentationLogPath,
        ];
    }
}
