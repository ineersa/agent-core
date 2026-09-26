<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\OpenCodeGo;

use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\CompositeModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/** Routes OpenCode Go's chat-completions and Responses models to their matching contracts. */
final readonly class OpenCodeGoProvider implements ProviderInterface
{
    private ModelCatalogInterface $catalog;

    public function __construct(
        private string $name,
        private ProviderInterface $completions,
        private ProviderInterface $responses,
    ) {
        $this->catalog = new CompositeModelCatalog([
            $completions->getModelCatalog(),
            $responses->getModelCatalog(),
        ]);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function supports(string|Model $model): bool
    {
        return $this->completions->supports($model) || $this->responses->supports($model);
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        if ($this->completions->supports($model)) {
            return $this->completions->invoke($model, $input, $options);
        }
        if ($this->responses->supports($model)) {
            return $this->responses->invoke($model, $input, $options);
        }

        throw new ModelNotFoundException(\sprintf('Model "%s" not found in OpenCode Go.', $model instanceof Model ? $model->getName() : $model));
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->catalog;
    }
}
