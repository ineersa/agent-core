<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;

final class FileRewindRuntimePortsAdapter implements FileRewindTurnPreviewPortInterface, FileRewindTurnActionPortInterface
{
    public function hasCheckpoint(string $sessionId, int $turnNo): bool
    {
        return FileRewindRuntimePortsHolder::instance()->ports()->hasCheckpoint($sessionId, $turnNo);
    }

    public function preview(string $sessionId, int $turnNo): array
    {
        return FileRewindRuntimePortsHolder::instance()->ports()->preview($sessionId, $turnNo);
    }

    public function execute(string $sessionId, int $turnNo, string $action): void
    {
        FileRewindRuntimePortsHolder::instance()->ports()->execute($sessionId, $turnNo, $action);
    }
}
