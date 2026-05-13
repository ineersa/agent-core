<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\TUI;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Symfony\Component\Console\Command\Command;

/**
 * Application-level TUI entry point.
 *
 * Receives an AgentSessionClient from the CLI command and runs the interactive
 * terminal UI. This is the only bridge between Symfony Console and Symfony TUI.
 *
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final class InteractiveMode
{
    /**
     * Run the interactive TUI for a given session client.
     *
     * @todo Wire actual Symfony TUI widgets and screens here.
     */
    public function run(AgentSessionClient $client): int
    {
        // Future: create Tui, attach screens, run event loop
        // $tui = new Tui(...);
        // $tui->run(new AgentScreen($client));
        // return $tui->getExitCode();

        return Command::SUCCESS;
    }
}
