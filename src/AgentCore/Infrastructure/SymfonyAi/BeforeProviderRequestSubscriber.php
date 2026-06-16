<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Model;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs normal {@see BeforeProviderRequestHookInterface} hooks first,
 * then applies final provider-compatibility normalization via
 * {@see ProviderCompatibilityRequestShaper}.
 *
 * This ensures that normal hooks (which may be third-party or extension
 * services) cannot accidentally see, consume, or corrupt internal compat
 * flags, and that compat shaping always happens as a final deterministic
 * step before the provider request.
 */
final readonly class BeforeProviderRequestSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<BeforeProviderRequestHookInterface> $hooks
     */
    public function __construct(
        private iterable $hooks = [],
        private ProviderCompatibilityRequestShaper $compatShaper = new ProviderCompatibilityRequestShaper(
            new NullProviderCompatibilityResolver(),
        ),
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

        $isMessageBag = $input instanceof \Symfony\AI\Platform\Message\MessageBag;

        if (!\is_array($input) && !$isMessageBag) {
            $event->setOptions($options);

            return;
        }

        // Wrap MessageBag as array so hooks receive a consistent array shape.
        $resolvedInput = $isMessageBag ? ['message_bag' => $input] : $input;
        $resolvedModel = $event->getModel()->getName();
        $resolvedOptions = $options;

        // ── Phase 1: normal before-provider hooks (extensions, app-level hooks) ──
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

        // ── Phase 2: final provider-compatibility normalization ──
        // Runs after all hooks so no third-party/hook code can see or corrupt
        // internal compat flags, and compat shaping is deterministic.
        $final = $this->compatShaper->shape($resolvedModel, $resolvedInput, $resolvedOptions);
        $resolvedModel = $final['model'];
        $resolvedInput = $final['input'];
        $resolvedOptions = $final['options'];

        if ($resolvedModel !== $event->getModel()->getName()) {
            $event->setModel(new Model(
                $resolvedModel,
                $event->getModel()->getCapabilities(),
                $event->getModel()->getOptions(),
            ));
        }

        // Unwrap if we wrapped it.
        $event->setInput($isMessageBag && isset($resolvedInput['message_bag']) ? $resolvedInput['message_bag'] : $resolvedInput);
        $event->setOptions($resolvedOptions);
    }
}
