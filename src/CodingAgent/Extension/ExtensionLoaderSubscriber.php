<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\CLI\Session\SessionListCommand;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads Hatfield extensions on console invocations, except read-only catalog discovery.
 *
 * Extensions must be loaded in every process — not just the main `agent`
 * command — because Messenger worker processes (messenger:consume) handle
 * tool execution in separate PHP processes with their own container.
 * Without loading here, the tool worker's ExtensionHookRegistry would be
 * empty and extension hooks (e.g. SafeGuard) would never fire.
 *
 * After extensions register tools, reapply process-transport tool filters so
 * the provider-visible ToolRegistry in controller/LLM workers matches the
 * parent CLI allowlist/denylist.
 */
final class ExtensionLoaderSubscriber implements EventSubscriberInterface
{
    private bool $loaded = false;

    /**
     * @param \Closure(): ExtensionManager        $extensionManager
     * @param \Closure(): ToolFilterRuntimeConfig $toolFilterConfig
     * @param \Closure(): ToolRegistryInterface   $toolRegistry
     */
    public function __construct(
        #[AutowireServiceClosure(ExtensionManager::class)]
        private readonly \Closure $extensionManager,
        #[AutowireServiceClosure(ToolFilterRuntimeConfig::class)]
        private readonly \Closure $toolFilterConfig,
        #[AutowireServiceClosure(ToolRegistryInterface::class)]
        private readonly \Closure $toolRegistry,
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
        if ($this->loaded || SessionListCommand::NAME === $event->getCommand()?->getName()) {
            return;
        }
        $this->loaded = true;

        $diagnostics = ($this->extensionManager)()->loadExtensions();

        // Symfony Process propagates the controller process environment
        // (including getenv/default env) on consumer launch/recycle. Controllers
        // also receive the same filters via --tools / --tools-excluded argv.
        $filter = ($this->toolFilterConfig)();
        $filter->hydrateFromEnvironment();
        $filter->applyToRegistry(($this->toolRegistry)());

        if ([] !== $diagnostics) {
            $this->logger->warning('Extension startup diagnostics', [
                'count' => \count($diagnostics),
                'diagnostics' => $diagnostics,
            ]);
        }
    }
}
