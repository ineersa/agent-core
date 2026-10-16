<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Attributes process_role from Symfony command options and Messenger receivers.
 *
 * Sets one ambient RunLogContext scope for the life of the console command or
 * Messenger worker. Leaves that scope on console terminate so reused PHPUnit
 * kernels do not leak roles across cases. Never scans raw argv.
 */
final class ProcessRoleAttributionSubscriber
{
    private bool $roleEntered = false;

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->roleEntered) {
            return;
        }

        $role = $this->resolveConsoleRole($event);
        if (null === $role) {
            return;
        }

        RunLogContext::enter(['process_role' => $role]);
        $this->roleEntered = true;
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1024)]
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (!$this->roleEntered) {
            return;
        }

        RunLogContext::leave();
        $this->roleEntered = false;
    }

    #[AsEventListener]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $transports = $event->getWorker()->getMetadata()->getTransportNames();
        if (1 !== \count($transports)) {
            return;
        }

        $role = ProcessRole::forMessengerTransport($transports[0]);
        if (null === $role) {
            return;
        }

        // WorkerStartedEvent fires after ConsoleEvents::COMMAND for
        // messenger:consume. Replace the generic console role with the
        // transport-specific worker role for the rest of the process.
        if ($this->roleEntered) {
            RunLogContext::leave();
            $this->roleEntered = false;
        }

        RunLogContext::enter(['process_role' => $role]);
        $this->roleEntered = true;
    }

    private function resolveConsoleRole(ConsoleCommandEvent $event): ?string
    {
        $command = $event->getCommand();
        if (null === $command) {
            return null;
        }

        $input = $event->getInput();
        $name = $command->getName();
        if ('agent' === $name) {
            $controller = $input->hasOption('controller') && true === $input->getOption('controller');
            $headless = $input->hasOption('headless') && true === $input->getOption('headless');

            return ProcessRole::forAgentCommand($controller, $headless);
        }

        if (null === $name || '' === $name) {
            return null;
        }

        if ('messenger:consume' === $name) {
            // Prefer WorkerStartedEvent's resolved transport names. Symfony may
            // still expand receivers after ConsoleEvents::COMMAND.
            return ProcessRole::CONSOLE;
        }

        // Unrelated console commands stay unattributed so resource queries do
        // not mix maintenance tools into Hatfield process roles.
        return null;
    }
}
