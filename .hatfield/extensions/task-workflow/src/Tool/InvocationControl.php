<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;

/**
 * Shared cooperative control for contextual task-workflow tools.
 *
 * Computes one monotonic deadline per invocation and returns documented
 * structured maps for cancel/timeout without throwing.
 */
final readonly class InvocationControl
{
    public function __construct(
        public ?ToolCancellationTokenInterface $cancellationToken,
        public ?int $timeoutSeconds,
        public ?int $deadlineNs,
    ) {
    }

    public static function fromContext(ToolInvocationContextDTO $context): self
    {
        $startedNs = hrtime(true);
        $timeoutSeconds = $context->timeoutSeconds;
        $deadlineNs = null;
        if (null !== $timeoutSeconds && $timeoutSeconds > 0) {
            $deadlineNs = $startedNs + ($timeoutSeconds * 1_000_000_000);
        }

        return new self(
            cancellationToken: $context->cancellationToken,
            timeoutSeconds: $timeoutSeconds,
            deadlineNs: $deadlineNs,
        );
    }

    /**
     * @return array{cancelled: true, message: string}|array{timed_out: true, timeout_seconds: int, message: string}|null
     */
    public function interrupted(?string $message = null): ?array
    {
        if (null !== $this->cancellationToken && $this->cancellationToken->isCancellationRequested()) {
            return [
                'cancelled' => true,
                'message' => $message ?? 'Cancelled by user/runtime.',
            ];
        }

        if (null !== $this->deadlineNs && hrtime(true) >= $this->deadlineNs) {
            return [
                'timed_out' => true,
                'timeout_seconds' => $this->timeoutSeconds ?? 0,
                'message' => $message ?? 'Timed out before completion.',
            ];
        }

        return null;
    }

    public function remainingTimeoutSeconds(?float $explicitTimeout = null): ?float
    {
        $remaining = null;
        if (null !== $this->deadlineNs) {
            $remainingNs = $this->deadlineNs - hrtime(true);
            $remaining = max(0.0, $remainingNs / 1_000_000_000);
        }

        if (null === $explicitTimeout) {
            return $remaining;
        }

        if (null === $remaining) {
            return $explicitTimeout;
        }

        return min($explicitTimeout, $remaining);
    }

    /**
     * Detect structured interrupt maps returned from lock wait / nested helpers.
     */
    public static function isInterruptMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        return true === ($value['cancelled'] ?? false) || true === ($value['timed_out'] ?? false);
    }
}
