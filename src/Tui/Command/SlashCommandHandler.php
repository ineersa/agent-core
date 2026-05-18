<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Contract for a handler that executes a slash command.
 *
 * Each registered command must provide an implementation. The handler
 * receives the parsed SlashCommand (name, args, original text) and
 * returns a CommandResult describing the side effects.
 *
 * Implementations should be stateless or idempotent — the registry
 * does not guarantee single invocation.
 */
interface SlashCommandHandler
{
    /**
     * Execute the command and return its result.
     */
    public function handle(SlashCommand $command): CommandResult;
}
