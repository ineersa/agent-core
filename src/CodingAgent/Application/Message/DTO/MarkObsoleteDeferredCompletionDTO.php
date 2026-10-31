<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** Metadata-only mark for an obsolete deferred completion inside the verified transaction. */
final readonly class MarkObsoleteDeferredCompletionDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deferredId,
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\PositiveOrZero]
        public int $turnNo,
        #[Assert\NotBlank]
        public string $stepId,
    ) {
    }
}
