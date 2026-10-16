<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Bounded original-call acknowledgment body for background wait resolution.
 *
 * status must stay running_in_background. Never treat this as a successful
 * ToolExecutor terminal cache entry or a completed native outcome.
 */
final readonly class ToolBackgroundAcknowledgement
{
    public const string STATUS = 'running_in_background';

    /**
     * @param array{tool: string, invocation_id: string, pid?: int}|null $inspection
     */
    public function __construct(
        public string $invocationId,
        public string $originRunId,
        public string $toolCallId,
        public string $tool,
        public string $completion = 'automatic',
        public ?array $inspection = null,
        public string $status = self::STATUS,
    ) {
        if ('' === $this->invocationId || '' === $this->originRunId || '' === $this->toolCallId || '' === $this->tool) {
            throw new \InvalidArgumentException('Background acknowledgment requires invocation, origin, tool-call, and tool identity.');
        }
        if (self::STATUS !== $this->status) {
            throw new \InvalidArgumentException('Background acknowledgment status must be running_in_background.');
        }
        if ('automatic' !== $this->completion) {
            throw new \InvalidArgumentException('Background acknowledgment completion must be automatic.');
        }
        if (null !== $this->inspection) {
            $tool = $this->inspection['tool'] ?? null;
            $inspectedInvocation = $this->inspection['invocation_id'] ?? null;
            if (!\is_string($tool) || '' === $tool || !\is_string($inspectedInvocation) || '' === $inspectedInvocation) {
                throw new \InvalidArgumentException('Background acknowledgment inspection requires tool and invocation_id.');
            }
            if (\array_key_exists('pid', $this->inspection) && (!\is_int($this->inspection['pid']) || $this->inspection['pid'] <= 0)) {
                throw new \InvalidArgumentException('Background acknowledgment inspection PID must be a positive integer when present.');
            }
            $unexpected = array_diff(array_keys($this->inspection), ['tool', 'invocation_id', 'pid']);
            if ([] !== $unexpected) {
                throw new \InvalidArgumentException('Background acknowledgment inspection contains unsupported fields.');
            }
        }
    }

    /**
     * @return array{
     *     status: string,
     *     invocation_id: string,
     *     origin_run_id: string,
     *     tool_call_id: string,
     *     tool: string,
     *     completion: string,
     *     inspection?: array{tool: string, invocation_id: string, pid?: int}
     * }
     */
    public function toResultPayload(): array
    {
        $payload = [
            'status' => $this->status,
            'invocation_id' => $this->invocationId,
            'origin_run_id' => $this->originRunId,
            'tool_call_id' => $this->toolCallId,
            'tool' => $this->tool,
            'completion' => $this->completion,
        ];
        if (null !== $this->inspection) {
            $payload['inspection'] = $this->inspection;
        }

        return $payload;
    }
}
