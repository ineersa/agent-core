<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Symfony\Component\Validator\Constraints as Assert;

/** One-way owner intent for durable Bash process metadata. */
final readonly class BashProcessCommandDTO
{
    public function __construct(
        #[Assert\Positive]
        public int $recordId,
        #[Assert\NotBlank]
        public string $runId,
        public BashProcessActionEnum $action,
    ) {
    }
}
