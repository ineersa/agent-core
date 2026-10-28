<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;

final readonly class BashRegistrationResultDTO
{
    /** @param list<string> $invalidFields */
    public function __construct(
        public BashRegistrationStatusEnum $status,
        public ?int $recordId = null,
        public ?int $pid = null,
        public ?string $logPath = null,
        public bool $processExists = false,
        public array $invalidFields = [],
    ) {
    }
}
