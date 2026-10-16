<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Symfony\Component\Console\Command\Command;
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
 * OS identity keeps the original launch title and appends hatfield.role.<role>
 * for Process Check matching. Temporary messenger:consume console attribution
 * stays in logs only until WorkerStartedEvent resolves a known transport.
 */
final class ProcessRoleAttributionSubscriber
{
    private ?Command $roleOwner = null;

    private ?string $baselineProcessTitle = null;

    private bool $processTitleApplied = false;

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if (null !== $this->roleOwner) {
            // Nested/unrelated console work must not steal the outer role scope.
            return;
        }

        $command = $event->getCommand();
        if (!$command instanceof Command) {
            return;
        }

        $role = $this->resolveConsoleRole($event);
        if (null === $role) {
            return;
        }

        $this->enterRole($role, $command, applyOsTitle: $role->isProcessCheckRole());
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1024)]
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (null === $this->roleOwner) {
            return;
        }

        $command = $event->getCommand();
        if ($command !== $this->roleOwner) {
            // Inner command termination must not clear the outer owner role.
            return;
        }

        $this->leaveRole(restoreBaselineTitle: true);
    }

    #[AsEventListener]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $transports = $event->getWorker()->getMetadata()->getTransportNames();
        if (1 !== \count($transports)) {
            // Unsupported multi-receiver consumers keep temporary console logs
            // and the original OS launch identity. Do not invent a combined role.
            return;
        }

        $role = ProcessRoleEnum::forMessengerTransport($transports[0]);
        if (null === $role) {
            return;
        }

        $owner = $this->roleOwner;
        if (null !== $owner) {
            // Replace temporary console ambient context with the known worker role.
            $this->leaveRole(restoreBaselineTitle: false);
        }

        $this->enterRole($role, $owner, applyOsTitle: true);
    }

    private function resolveConsoleRole(ConsoleCommandEvent $event): ?ProcessRoleEnum
    {
        $command = $event->getCommand();
        if (!$command instanceof Command) {
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

    private function enterRole(ProcessRoleEnum $role, ?Command $owner, bool $applyOsTitle): void
    {
        RunLogContext::enter(['process_role' => $role->value]);
        $this->roleOwner = $owner;

        if ($applyOsTitle) {
            $this->applyRoleProcessTitle($role, $owner);
        }
    }

    private function leaveRole(bool $restoreBaselineTitle): void
    {
        RunLogContext::leave();
        $this->roleOwner = null;

        if ($restoreBaselineTitle && $this->processTitleApplied) {
            $this->restoreBaselineProcessTitle();
        }
    }

    private function applyRoleProcessTitle(ProcessRoleEnum $role, ?Command $command): void
    {
        if (!$role->isProcessCheckRole()) {
            return;
        }

        if (!\function_exists('cli_set_process_title')) {
            // Intentional local degradation: keep original OS identity rather
            // than inventing a replacement title without title APIs.
            error_log('process.role.title_unavailable role='.$role->value);

            return;
        }

        if (null === $this->baselineProcessTitle) {
            $baseline = $this->captureBaselineProcessTitle();
            if (null === $baseline) {
                // Intentional local degradation: without a readable original
                // identity, leave the OS title unchanged.
                error_log('process.role.title_baseline_unavailable role='.$role->value);

                return;
            }
            $this->baselineProcessTitle = $baseline;
        }

        $marker = $role->processTitleMarker();
        $title = $this->composeTitle($this->baselineProcessTitle, $marker);

        if ($command instanceof Command) {
            // ConsoleEvents::COMMAND runs before Command::run(). Storing the
            // composed title here lets Symfony apply it at the start of run().
            $command->setProcessTitle($title);
        }

        // WorkerStartedEvent happens after Command::run() already started, so
        // update the live OS title immediately. Best-effort only.
        @cli_set_process_title($title);
        $this->processTitleApplied = true;
    }

    private function restoreBaselineProcessTitle(): void
    {
        if (!\function_exists('cli_set_process_title') || null === $this->baselineProcessTitle) {
            $this->processTitleApplied = false;
            $this->baselineProcessTitle = null;

            return;
        }

        @cli_set_process_title($this->baselineProcessTitle);
        $this->processTitleApplied = false;
        $this->baselineProcessTitle = null;
    }

    private function captureBaselineProcessTitle(): ?string
    {
        if (\function_exists('cli_get_process_title')) {
            $current = cli_get_process_title();
            if (\is_string($current) && '' !== $current) {
                return $current;
            }
        }

        // Before the first cli_set_process_title(), PHP reports an empty process
        // title while /proc/<pid>/cmdline still holds the OS launch identity.
        // Read only this process's cmdline; do not scan other processes.
        $cmdline = @file_get_contents('/proc/self/cmdline');
        if (!\is_string($cmdline) || '' === $cmdline) {
            return null;
        }

        $joined = rtrim(str_replace("\0", ' ', $cmdline));

        return '' !== $joined ? $joined : null;
    }

    private function composeTitle(string $baseline, string $marker): string
    {
        $trimmed = rtrim($baseline);
        if ('' === $trimmed) {
            return $marker;
        }

        if (str_ends_with($trimmed, $marker)) {
            return $trimmed;
        }

        return $trimmed.' '.$marker;
    }
}
