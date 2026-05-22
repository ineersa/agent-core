<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Handles a single controller command type.
 *
 * Each handler receives the decoded RuntimeCommand and a callable to emit
 * runtime events back to the controller's stdout. Implementations are
 * focused on one command type (start_run, user_message, cancel, resume)
 * and are wired through Symfony autowiring.
 */
interface CommandHandlerInterface
{
    /**
     * Execute the command, emitting runtime events via the callable.
     *
     * @param RuntimeCommand               $command the decoded command from stdin
     * @param callable(RuntimeEvent): void $emit    emit a runtime event to stdout
     */
    public function handle(RuntimeCommand $command, callable $emit): void;
}
