<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Extension\ExtensionUpdateService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Process;

/** Own the consent gate before ExtensionLoaderSubscriber declares any extension code. */
final class ExtensionUpdateStartupSubscriber implements EventSubscriberInterface
{
    // /reload rebuilds the kernel in the same PHP process. Its old extension
    // classes cannot be undeclared, so check only on the initial owning startup.
    // Do not use environment state: that would leak this decision into workers.
    private static bool $checked = false;

    public function __construct(private readonly ExtensionUpdateService $updates, private readonly AppConfig $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['onConsoleCommand', 256]];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();
        $sessionId = getenv('HATFIELD_SESSION_ID');
        $ownedWorker = false !== $sessionId && '' !== $sessionId;
        if (self::$checked || 'agent' !== $event->getCommand()?->getName() || !$input->isInteractive()
            || true === $input->getOption('headless') || true === $input->getOption('controller')
            || $ownedWorker
        ) {
            return;
        }
        $io = new SymfonyStyle($input, $event->getOutput());
        $io->text('Checking installed extension updates before session startup…');
        try {
            $plans = $this->updates->prepare($this->config->cwd);
            if ([] !== $plans) {
                foreach ($plans as $plan) {
                    $io->section(ucfirst($plan->scope).' extensions: '.$plan->root);
                    $io->table(['Package (including dependencies)', 'Installed', 'Proposed'], array_map(
                        static fn (array $change): array => [$change['package'], $change['current'], $change['target']],
                        $plan->changes,
                    ));
                    if ([] !== $plan->requirementChanges) {
                        $io->table(['Direct extension requirement', 'Current', 'Proposed'], array_map(
                            static fn (array $change): array => [$change['package'], $change['current'], $change['target']],
                            $plan->requirementChanges,
                        ));
                    }
                    $io->text('Apply proposed composer.json SHA256 '.hash('sha256', $plan->manifestContents));
                    $io->text('Apply proposed composer.lock SHA256 '.hash('sha256', $plan->lockContents).', then run:');
                    $environment = [];
                    foreach (ExtensionUpdateService::environment($plan->root) as $name => $value) {
                        $environment[] = $name.'='.escapeshellarg($value);
                    }
                    $io->writeln(implode(' ', $environment).' '.(new Process($plan->installCommand))->getCommandLine(), SymfonyStyle::OUTPUT_RAW);
                }
                $io->note('Project-local packages take precedence over user-wide packages. Declining leaves both installations unchanged. Composer scripts and plugins are disabled.');
                if ($io->confirm('Apply exactly these extension updates before starting the session?', false)) {
                    $this->updates->apply($plans);
                    $io->success('Approved extension updates completed. Starting with the updated autoloaders.');
                } else {
                    $io->text('Updates declined; continuing with the installed versions.');
                }
            }
            self::$checked = true;
        } finally {
            // An exception propagates through Console and prevents the lower
            // priority extension loader and command/session from running.
            $this->updates->cleanup();
        }
    }
}
