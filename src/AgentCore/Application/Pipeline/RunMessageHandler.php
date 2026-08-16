<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Run\RunState;

interface RunMessageHandler
{
    /**
     * Structured-log component attributed to messages this handler processes.
     *
     * Ordinary pipeline handlers keep the default `runtime`; handlers that own
     * a dedicated runtime lane (LLM, tool, compaction) override the constant so
     * RunMessageProcessor never needs to know concrete handler class names.
     */
    public const string LOG_COMPONENT = 'runtime';

    public function supports(object $message): bool;

    public function handle(object $message, RunState $state): HandlerResult;
}
