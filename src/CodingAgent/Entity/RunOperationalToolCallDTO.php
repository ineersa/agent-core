<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

/** Current tool-call coordination only; concrete tool payload stays elsewhere. */
final readonly class RunOperationalToolCallDTO
{
    public function __construct(
        public string $batchId,
        public string $toolCallId,
        public int $orderIndex,
        public string $status,
        public int $attempt,
    ) {
        foreach (['batchId' => $batchId, 'toolCallId' => $toolCallId] as $name => $value) {
            if ('' === trim($value) || mb_strlen($value) > RunOperationalProjectionDTO::ID_MAX_LENGTH) {
                throw new \InvalidArgumentException($name.' must be bounded and non-blank.');
            }
        }
        if ('' === trim($status) || mb_strlen($status) > RunOperationalProjectionDTO::STATUS_MAX_LENGTH) {
            throw new \InvalidArgumentException('status must be bounded and non-blank.');
        }
        if ($orderIndex < 0 || $attempt < 0) {
            throw new \InvalidArgumentException('Tool order and attempt must not be negative.');
        }
    }
}
