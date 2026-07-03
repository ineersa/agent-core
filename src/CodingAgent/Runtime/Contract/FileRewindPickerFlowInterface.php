<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

interface FileRewindPickerFlowInterface
{
    public function isWired(): bool;

    public function open(string $sessionId): void;
}
