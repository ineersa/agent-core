<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Binds typed Hatfield invocation state while exposing only prepared options to Symfony AI.
 */
final readonly class PreparedInvocationPlatform implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private ProviderRequestPreparer $preparer,
        private ResolvedModel $resolvedModel,
        private ModelInvocationInput $invocationInput,
        private CancellationTokenInterface $cancelToken,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        if (!$input instanceof MessageBag && !\is_array($input)) {
            return $this->inner->invoke($model, $input, $options);
        }

        $prepared = $this->preparer->prepare(
            $this->resolvedModel,
            $input,
            array_replace($options, $this->resolvedModel->providerOptions),
            $this->invocationInput,
            $this->cancelToken,
        );

        return $this->inner->invoke($prepared['model'], $prepared['input'], $prepared['options']);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
