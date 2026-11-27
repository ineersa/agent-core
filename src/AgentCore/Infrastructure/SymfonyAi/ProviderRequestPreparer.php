<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Finishes Hatfield request preparation before the Symfony AI provider boundary.
 */
final readonly class ProviderRequestPreparer
{
    public function __construct(
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
    ): array {
        $messageBag = $input instanceof MessageBag;
        $requestInput = $messageBag ? ['message_bag' => $input] : $input;

        $prepared = $this->compatShaper->shape($resolvedModel, $requestInput, $options);
        $model = $prepared['model'];
        $requestInput = $prepared['input'];
        $options = $prepared['options'];

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
