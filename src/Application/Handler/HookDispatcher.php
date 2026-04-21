<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Event\BoundaryHookEvent;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches boundary and extension hooks via Symfony's event dispatcher and iterates registered hook subscribers.
 */
final readonly class HookDispatcher
{
    public function __construct(
        private HookSubscriberRegistry $registry,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Executes registered subscribers for a given hook name with context.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function dispatch(string $hookName, array $context = []): array
    {
        $isBoundaryHook = BoundaryHookName::isBoundary($hookName);
        $isExtensionHook = BoundaryHookName::isExtensionHook($hookName);

        if (!$isBoundaryHook && !$isExtensionHook) {
            throw new \InvalidArgumentException(\sprintf('Unknown boundary hook "%s". Allowed hooks: %s.', $hookName, implode(', ', BoundaryHookName::ALL)));
        }

        $event = new BoundaryHookEvent($hookName, $context);
        $this->eventDispatcher->dispatch($event, $hookName);

        foreach ($this->registry->subscribersFor($hookName) as $subscriber) {
            $event->context = $subscriber->handle($hookName, $event->context);
        }

        return $event->context;
    }
}
