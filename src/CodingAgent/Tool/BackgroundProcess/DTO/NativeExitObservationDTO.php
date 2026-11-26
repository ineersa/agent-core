<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

/** Native wrapper exit evidence; exited with a null exit means the outcome is unknown. */
final readonly class NativeExitObservationDTO
{
    public function __construct(
        public ?NativeProcessExitDTO $exit,
        public bool $exited,
    ) {
    }
}
