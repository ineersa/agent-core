<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode\DTO;

use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class InterruptCodeModeDTO
{
    /** @param list<string> $identities */
    public function __construct(
        #[Assert\All([new Assert\Uuid()])]
        public array $identities,
        public CodeModeInterruptionReasonEnum $reason,
    ) {
    }
}
