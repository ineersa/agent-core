<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Latest bounded coordination projection. Payload/history fields are
 * intentionally absent: canonical events remain their only durable source.
 */
final readonly class RunOperationalProjectionDTO
{
    public const int ID_MAX_LENGTH = 255;
    public const int STATUS_MAX_LENGTH = 32;

    public function __construct(
        public string $runId,
        public string $ownerSessionId,
        public RunStatus $status,
        public int $turnNo,
        public ?string $activeStepId,
        public ?CurrentOperationDTO $currentOperation,
        public ?string $lastAppliedAdvanceKey,
        public ?string $lastAppliedCompactionKey,
        public bool $retryableFailure,
        public int $retryAttempts,
        public int $lastEventSequence,
        public int $transitionVersion,
    ) {
        self::assertBounded($runId, 'runId');
        self::assertBounded($ownerSessionId, 'ownerSessionId');
        foreach ([
            'activeStepId' => $activeStepId,
            'operation step id' => $currentOperation?->stepId,
            'operation key' => $currentOperation?->idempotencyKey,
            'lastAppliedAdvanceKey' => $lastAppliedAdvanceKey,
            'lastAppliedCompactionKey' => $lastAppliedCompactionKey,
        ] as $name => $value) {
            if (null !== $value) {
                self::assertBounded($value, $name);
            }
        }
        foreach (['turnNo' => $turnNo, 'retryAttempts' => $retryAttempts, 'lastEventSequence' => $lastEventSequence, 'transitionVersion' => $transitionVersion] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException($name.' must not be negative.');
            }
        }
    }

    private static function assertBounded(string $value, string $name): void
    {
        if ('' === trim($value) || mb_strlen($value) > self::ID_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('%s must be non-blank and at most %d characters.', $name, self::ID_MAX_LENGTH));
        }
    }
}
