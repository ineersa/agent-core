<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

/**
 * Optional capability for {@see RunMessageHandler} implementations that own a
 * dedicated structured-log component (e.g. llm, tool, compaction).
 *
 * Ordinary pipeline handlers omit it and are attributed `runtime`;
 * RunMessageProcessor never needs to know concrete handler class names.
 */
interface RunMessageHandlerLogComponentInterface
{
    public function getLogComponent(): string;
}
