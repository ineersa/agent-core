<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** One-way readiness report from the code_mode host after the script socket is ready. */
final readonly class ReadyCodeModeInvocationDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\NotBlank]
        public string $deferredId,
    ) {
    }
}
