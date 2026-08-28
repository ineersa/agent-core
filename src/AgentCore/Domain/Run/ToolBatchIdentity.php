<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/** Derives the bounded batch identity shared by live transitions and replay. */
final class ToolBatchIdentity
{
    public static function fromTurnAndStep(int $turnNo, string $stepId): string
    {
        if ($turnNo < 0 || '' === trim($stepId)) {
            throw new \InvalidArgumentException('Tool batch requires a non-negative turn and non-blank step.');
        }

        return 't'.$turnNo.'-s'.hash('sha256', $stepId);
    }
}
