<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Symfony\AI\Platform\Event\ModelRoutingEvent;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ModelResolverRoutingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ?ModelResolverInterface $modelResolver = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ModelRoutingEvent::class => 'onModelRouting',
        ];
    }

    public function onModelRouting(ModelRoutingEvent $event): void
    {
        if (null === $this->modelResolver) {
            return;
        }

        $input = $event->getInput();

        if ($input instanceof MessageBag) {
            $messageBag = $input;
        } elseif (\is_array($input) && ($input['message_bag'] ?? null) instanceof MessageBag) {
            $messageBag = $input['message_bag'];
        } else {
            return;
        }

        $metadata = PlatformInvocationMetadata::extract($event->getOptions());
        if (null === $metadata) {
            return;
        }

        $options = PlatformInvocationMetadata::strip($event->getOptions());
        $resolvedModel = $this->modelResolver->resolve(
            $event->getModel(),
            $messageBag,
            $metadata->input,
            new ModelResolutionOptions($options),
        );

        $event->setModel($resolvedModel->model);
        $event->setOptions(PlatformInvocationMetadata::inject(array_replace($options, $resolvedModel->options), $metadata));
    }
}
