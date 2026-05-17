<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;

final class ModelSelectionService
{
    public const LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly HomeSettingsWriter $homeWriter,
        private readonly SessionMetadataStore $sessionMetaStore,
    ) {
    }

    public function resolveInitialModel(
        ?string $explicitModel = null,
        string $sessionId = '',
    ): ?AiModelReference {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return null;
        }

        if (null !== $explicitModel) {
            $ref = AiModelReference::tryParse($explicitModel);
            if (null !== $ref && $catalog->isAvailable($ref)) {
                return $ref;
            }
        }

        if ('' !== $sessionId) {
            $meta = $this->sessionMetaStore->readSessionMetadata($sessionId);
            $sessionModel = \is_string($meta['model'] ?? null) ? $meta['model'] : null;
            if (null !== $sessionModel) {
                $ref = AiModelReference::tryParse($sessionModel);
                if (null !== $ref && $catalog->isAvailable($ref)) {
                    return $ref;
                }
            }
        }

        $defaultRef = $catalog->defaultModelReference();
        if (null !== $defaultRef && $catalog->isAvailable($defaultRef)) {
            return $defaultRef;
        }

        return $catalog->firstAvailableModel();
    }

    public function getAvailableModels(): array
    {
        $catalog = $this->appConfig->catalog;

        return null !== $catalog ? $catalog->allModels() : [];
    }

    public function resolveInitialReasoning(
        ?string $explicitReasoning = null,
        string $sessionId = '',
    ): string {
        if (null !== $explicitReasoning) {
            return $explicitReasoning;
        }

        if ('' !== $sessionId) {
            $meta = $this->sessionMetaStore->readSessionMetadata($sessionId);
            $sessionReasoning = \is_string($meta['reasoning'] ?? null) ? $meta['reasoning'] : null;
            if (null !== $sessionReasoning) {
                return $sessionReasoning;
            }
        }

        $defaultReasoning = $this->appConfig->ai?->defaultReasoning;
        if (null !== $defaultReasoning && '' !== $defaultReasoning) {
            return $defaultReasoning;
        }

        return 'medium';
    }

    public function changeModel(AiModelReference $model, string $sessionId): void
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            throw new \RuntimeException('No AI configuration available.');
        }
        if (!$catalog->isAvailable($model)) {
            throw new \RuntimeException(\sprintf('Model "%s" is not available.', $model->toString()));
        }

        $this->homeWriter->writeDefaultModel($model->toString());
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'model' => $model->toString(),
            'model_provider' => $model->providerId,
            'model_name' => $model->modelName,
        ]);
    }

    public function changeReasoning(string $level, string $sessionId): void
    {
        if (!\in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid reasoning level "%s". Valid levels: %s.', $level, implode(', ', self::LEVELS)));
        }

        $this->homeWriter->writeDefaultReasoning($level);
        $this->sessionMetaStore->writeSessionMetadata($sessionId, ['reasoning' => $level]);
    }
}
