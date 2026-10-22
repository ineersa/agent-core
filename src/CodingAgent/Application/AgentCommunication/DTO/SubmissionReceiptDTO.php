<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication\DTO;

final readonly class SubmissionReceiptDTO
{
    public function __construct(
        public string $identity,
        public string $sourceRunId,
        public string $toolName,
        public string $parentRunId,
        public string $childRunId,
        public string $lifecycleId,
        public string $text,
        public bool $backgrounded,
    ) {
    }
}
