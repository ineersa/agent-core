<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

interface FileRewindTurnPreviewPortInterface
{
    public function hasCheckpoint(string $sessionId, int $turnNo): bool;

    /** @return list<array{path:string,status:string,added:int,removed:int}> */
    public function preview(string $sessionId, int $turnNo): array;
}
