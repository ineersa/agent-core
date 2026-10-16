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
 *
 * Also sets the OS process title through Symfony Command::setProcessTitle().
 * ConsoleEvents::COMMAND fires before Command::run(), so the stored title is
 * applied when run() starts. WorkerStartedEvent can replace the temporary
 * messenger:consume console title after transports resolve.
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

        $this->enterRole($role, $event->getCommand());
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
            // Unsupported multi-receiver consumers keep the temporary console
            // role (or none). Do not invent a combined process_role.
            return;
        }

        $role = ProcessRoleEnum::forMessengerTransport($transports[0]);
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

        $this->enterRole($role);
    }

    private function resolveConsoleRole(ConsoleCommandEvent $event): ?ProcessRoleEnum
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

            return ProcessRoleEnum::forAgentCommand($controller, $headless);
        }

        if (null === $name || '' === $name) {
            return null;
        }

        if ('messenger:consume' === $name) {
            // Prefer WorkerStartedEvent's resolved transport names. Symfony may
            // still expand receivers after ConsoleEvents::COMMAND.
            return ProcessRoleEnum::Console;
        }

        // Unrelated console commands stay unattributed so resource queries do
        // not mix maintenance tools into Hatfield process roles.
        return null;
    }

    private function enterRole(ProcessRoleEnum $role, ?object $command = null): void
    {
        RunLogContext::enter(['process_role' => $role->value]);
        $this->roleEntered = true;
        $this->applyProcessTitle($role, $command);
    }

    private function applyProcessTitle(ProcessRoleEnum $role, ?object $command = null): void
    {
        $title = $role->processTitle();

        if (null !== $command && method_exists($command, 'setProcessTitle')) {
            // ConsoleEvents::COMMAND runs before Command::run(). Storing the
            // title here lets Symfony apply it at the start of run().
            $command->setProcessTitle($title);
        }

        // WorkerStartedEvent and non-Command wrappers need an immediate OS
        // title update. Best-effort: title changes must never interrupt work.
        if (\function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } elseif (\function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }
}
