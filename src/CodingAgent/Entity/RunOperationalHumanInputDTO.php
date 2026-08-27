<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

/** Current human-input coordination only; question and answer payload stay canonical. */
final readonly class RunOperationalHumanInputDTO
{
    public function __construct(
        public string $questionId,
        public int $orderIndex,
        public string $continuationKind,
        public ?string $toolCallId,
        public string $status,
    ) {
        foreach (['questionId' => $questionId, 'continuationKind' => $continuationKind, 'status' => $status] as $name => $value) {
            $max = 'continuationKind' === $name || 'status' === $name
                ? RunOperationalProjectionDTO::STATUS_MAX_LENGTH
                : RunOperationalProjectionDTO::ID_MAX_LENGTH;
            if ('' === trim($value) || mb_strlen($value) > $max) {
                throw new \InvalidArgumentException($name.' must be bounded and non-blank.');
            }
        }
        if (null !== $toolCallId && ('' === trim($toolCallId) || mb_strlen($toolCallId) > RunOperationalProjectionDTO::ID_MAX_LENGTH)) {
            throw new \InvalidArgumentException('toolCallId must be bounded and non-blank when present.');
        }
        if ($orderIndex < 0) {
            throw new \InvalidArgumentException('Human-input order must not be negative.');
        }
    }
}
