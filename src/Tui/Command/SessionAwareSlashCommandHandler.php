<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

interface SessionAwareSlashCommandHandler
{
    public function setSessionId(string $sessionId): void;
}
