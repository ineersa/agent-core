<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Finishes Hatfield request preparation before the Symfony AI provider boundary.
 */
final readonly class ProviderRequestPreparer
{
    /**
     * @param iterable<BeforeProviderRequestHookInterface> $hooks
     */
    public function __construct(
        private iterable $hooks = [],
        private ProviderCompatibilityRequestShaper $compatShaper = new ProviderCompatibilityRequestShaper(),
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param MessageBag|array<string, mixed> $input
     * @param array<string, mixed>            $options provider-facing options only
     *
     * @return array{model: string, input: MessageBag|array<string, mixed>, options: array<string, mixed>}
     */
    public function prepare(
        ResolvedModel $resolvedModel,
        MessageBag|array $input,
        array $options,
        ModelInvocationInput $invocationInput,
        ?CancellationTokenInterface $cancelToken,
    ): array {
        $messageBag = $input instanceof MessageBag;
        $requestInput = $messageBag ? ['message_bag' => $input] : $input;

        $prepared = $this->compatShaper->shape($resolvedModel, $requestInput, $options);
        $model = $prepared['model'];
        $requestInput = $prepared['input'];
        $options = $prepared['options'];

        foreach ($this->hooks as $hook) {
            $request = $hook->beforeProviderRequest($model, $requestInput, $options, $cancelToken);
            if (null === $request) {
                continue;
            }

            $prepared = $request->applyOn($model, $requestInput, $options);
            $model = $prepared['model'];
            $requestInput = $prepared['input'];
            $options = $prepared['options'];
        }

        $finalInput = $messageBag && isset($requestInput['message_bag']) && $requestInput['message_bag'] instanceof MessageBag
            ? $requestInput['message_bag']
            : $requestInput;

        $this->eventDispatcher?->dispatch(new ProviderRequestPreparedEvent(
            $invocationInput,
            $resolvedModel,
            $model,
            $finalInput,
            $options,
        ));

        return [
            'model' => $model,
            'input' => $finalInput,
            'options' => $options,
        ];
    }
}
