<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * The RunMessageHandler maps a bus message and current run state into a declarative transition result consumed by the message processor.
 */
interface RunMessageHandler
{
    public function supports(object $message): bool;

    public function handle(object $message, RunState $state): HandlerResult;
}
