<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

final readonly class NativeProcessExitDTO
{
    public function __construct(
        public int $exitCode,
        public int $logWriterExitCode,
    ) {
    }
}
