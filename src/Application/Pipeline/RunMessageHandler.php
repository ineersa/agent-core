<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Run\RunState;

interface RunMessageHandler
{
    public function supports(object $message): bool;

    public function handle(object $message, RunState $state): HandlerResult;
}
