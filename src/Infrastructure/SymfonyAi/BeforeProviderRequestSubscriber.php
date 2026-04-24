<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Model;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class BeforeProviderRequestSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<BeforeProviderRequestHookInterface> $hooks
     */
    public function __construct(
        private iterable $hooks = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvocationEvent::class => 'onInvocation',
        ];
    }

    public function onInvocation(InvocationEvent $event): void
    {
        $options = PlatformInvocationMetadata::strip($event->getOptions());
        $metadata = PlatformInvocationMetadata::extract($event->getOptions());
        $input = $event->getInput();

        if (!\is_array($input)) {
            $event->setOptions($options);

            return;
        }

        $resolvedModel = $event->getModel()->getName();
        $resolvedInput = $input;
        $resolvedOptions = $options;

        foreach ($this->hooks as $hook) {
            $request = $hook->beforeProviderRequest($resolvedModel, $resolvedInput, $resolvedOptions, $metadata?->cancelToken);
            if (null === $request) {
                continue;
            }

            $resolved = $request->applyOn($resolvedModel, $resolvedInput, $resolvedOptions);
            $resolvedModel = $resolved['model'];
            $resolvedInput = $resolved['input'];
            $resolvedOptions = $resolved['options'];
        }

        if ($resolvedModel !== $event->getModel()->getName()) {
            $event->setModel(new Model(
                $resolvedModel,
                $event->getModel()->getCapabilities(),
                $event->getModel()->getOptions(),
            ));
        }

        $event->setInput($resolvedInput);
        $event->setOptions($resolvedOptions);
    }
}
