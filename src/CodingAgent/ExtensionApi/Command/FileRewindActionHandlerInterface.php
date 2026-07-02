<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

interface FileRewindActionHandlerInterface
{
    public function execute(string $sessionId, int $turnNo, FileRewindActionEnum $action): void;
}
