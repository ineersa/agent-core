<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;

/**
 * Wire-safe scalar encoding for {@see RepairResult} over JSONL runtime events.
 */
final class RepairResultNormalizer
{
    /**
     * @return array{
     *     repairable_stale_cancellation_detected: bool,
     *     stale_cancellation_repaired: bool,
     *     message: string,
     *     refusal_reason: ?string,
     *     active_operations_redriven: int
     * }
     */
    public static function toArray(RepairResult $result): array
    {
        return [
            'repairable_stale_cancellation_detected' => $result->repairableStaleCancellationDetected,
            'stale_cancellation_repaired' => $result->staleCancellationRepaired,
            'message' => $result->message,
            'refusal_reason' => $result->refusalReason?->value,
            'active_operations_redriven' => $result->activeOperationsRedriven,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): RepairResult
    {
        if (!\is_bool($payload['repairable_stale_cancellation_detected'] ?? null)
            || !\is_bool($payload['stale_cancellation_repaired'] ?? null)
            || !\is_string($payload['message'] ?? null)
            || !\is_int($payload['active_operations_redriven'] ?? null)
            || $payload['active_operations_redriven'] < 0
            || !\array_key_exists('refusal_reason', $payload)
            || (null !== $payload['refusal_reason'] && !\is_string($payload['refusal_reason']))) {
            throw new \InvalidArgumentException('Invalid repair result payload.');
        }

        $refusalRaw = $payload['refusal_reason'] ?? null;
        $refusalReason = null;
        if (\is_string($refusalRaw)) {
            $refusalReason = SessionRepairRefusalReasonEnum::tryFrom($refusalRaw);
            if (null === $refusalReason) {
                throw new \InvalidArgumentException(\sprintf('Unknown repair refusal_reason "%s".', $refusalRaw));
            }
        }

        return new RepairResult(
            repairableStaleCancellationDetected: $payload['repairable_stale_cancellation_detected'],
            staleCancellationRepaired: $payload['stale_cancellation_repaired'],
            message: $payload['message'],
            refusalReason: $refusalReason,
            activeOperationsRedriven: $payload['active_operations_redriven'],
        );
    }
}
