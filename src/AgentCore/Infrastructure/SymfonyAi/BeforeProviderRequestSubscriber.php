<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Model;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs provider-compatibility normalization FIRST (so compat shapers can
 * inject provider-specific options/input shapes), THEN normal
 * {@see BeforeProviderRequestHookInterface} hooks.
 *
 * This ensures that normal hooks (which may be third-party or extension
 * services) receive already-shaped compat options and that compat shaping
 * always happens as a deterministic first step. Internal option keys are
 * stripped after compat shaping so hooks never see them.
 */
final readonly class BeforeProviderRequestSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<BeforeProviderRequestHookInterface> $hooks
     */
    public function __construct(
        private iterable $hooks = [],
        private ProviderCompatibilityRequestShaper $compatShaper = new ProviderCompatibilityRequestShaper(),
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

        // ── Phase 1: compat shaping (BEFORE normal hooks) ──
        // Runs first so that compat injection (e.g. empty thinking for DeepSeek)
        // happens before any third-party hook inspects or mutates the input.
        // Internal keys are stripped inside shape() so hooks never see them.
        $final = $this->compatShaper->shape($resolvedModel, $resolvedInput, $resolvedOptions);
        $resolvedModel = $final['model'];
        $resolvedInput = $final['input'];
        $resolvedOptions = $final['options'];

        // ── Phase 2: normal before-provider hooks (extensions, app-level hooks) ──
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

        // Unwrap if we wrapped it.
        $event->setInput($isMessageBag && isset($resolvedInput['message_bag']) ? $resolvedInput['message_bag'] : $resolvedInput);
        $event->setOptions($resolvedOptions);
    }
}
