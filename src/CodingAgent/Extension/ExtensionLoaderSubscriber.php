<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads Hatfield extensions on every console command invocation.
 *
 * Extensions must be loaded in every process — not just the main `agent`
 * command — because Messenger worker processes (messenger:consume) handle
 * tool execution in separate PHP processes with their own container.
 * Without loading here, the tool worker's ExtensionHookRegistry would be
 * empty and extension hooks (e.g. SafeGuard) would never fire.
 */
final class ExtensionLoaderSubscriber implements EventSubscriberInterface
{
    private bool $loaded = false;

    public function __construct(
        private readonly ExtensionManager $extensionManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommand',
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $diagnostics = $this->extensionManager->loadExtensions();

        if ([] !== $diagnostics) {
            $this->logger->warning('Extension startup diagnostics', [
                'count' => \count($diagnostics),
                'diagnostics' => $diagnostics,
            ]);
        }
    }
}
