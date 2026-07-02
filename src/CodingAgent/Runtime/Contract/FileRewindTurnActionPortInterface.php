<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

interface FileRewindTurnActionPortInterface
{
    public function execute(string $sessionId, int $turnNo, string $action): void;
}
