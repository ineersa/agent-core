<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Central model/reasoning selection with four-tier priority and persistence.
 *
 * Model resolution priority:
 *  1. explicit request (CLI --model, StartRunRequest.model)
 *  2. session metadata (model key in metadata.yaml)
 *  3. Hatfield ai.default_model
 *  4. first available configured model
 *
 * Reasoning mirrors model selection, falling back to medium.
 *
 * On change: persists both home default and session metadata.
 *
 * This service depends only on CodingAgent config services.
 * It does not import AgentCore, Tui, HttpFoundation, or FrameworkBundle.
 */
final class ModelSelectionService
{
    /** Valid reasoning levels. */
    public const LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly AppConfigResolver $configResolver,
        private readonly HomeSettingsWriter $homeWriter,
        private readonly SessionMetadataStore $sessionMetaStore,
    ) {
    }

    // ──────────────────────────────────────────────
    //  Model resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial model for a session.
     *
     * @param string|null $explicitModel Explicit request (e.g. "deepseek/deepseek-v4-pro")
     * @param string      $sessionId     Session ID for metadata lookup (empty for new sessions)
     * @param string      $projectCwd    Project working directory
     *
     * @return AiModelReference|null Null only if no models are configured at all
     */
    public function resolveInitialModel(
        ?string $explicitModel = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): ?AiModelReference {
        $catalog = $this->catalog($projectCwd);

        if (null === $catalog) {
            return null;
        }

        // 1. Explicit request
        if (null !== $explicitModel) {
            $ref = AiModelReference::tryParse($explicitModel);
            if (null !== $ref && $catalog->isAvailable($ref)) {
                return $ref;
            }
        }

        // 2. Session metadata
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

        // 3. Hatfield ai.default_model
        $defaultRef = $catalog->defaultModelReference();
        if (null !== $defaultRef && $catalog->isAvailable($defaultRef)) {
            return $defaultRef;
        }

        // 4. First available
        return $catalog->firstAvailableModel();
    }

    /**
     * Get all available model references for the current project.
     *
     * @return list<AiModelReference>
     */
    public function getAvailableModels(string $projectCwd = ''): array
    {
        $catalog = $this->catalog($projectCwd);

        return null !== $catalog ? $catalog->allModels() : [];
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial reasoning level for a session.
     *
     * @param string|null $explicitReasoning Explicit request (e.g. "high")
     * @param string      $sessionId         Session ID for metadata lookup
     * @param string      $projectCwd        Project working directory
     *
     * @return string A reasoning level from {@see LEVELS}
     */
    public function resolveInitialReasoning(
        ?string $explicitReasoning = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): string {
        // 1. Explicit request
        if (null !== $explicitReasoning) {
            return $explicitReasoning;
        }

        // 2. Session metadata
        if ('' !== $sessionId) {
            $meta = $this->sessionMetaStore->readSessionMetadata($sessionId);
            $sessionReasoning = \is_string($meta['reasoning'] ?? null) ? $meta['reasoning'] : null;
            if (null !== $sessionReasoning) {
                return $sessionReasoning;
            }
        }

        // 3. Hatfield ai.default_reasoning
        $config = $this->configResolver->resolve($projectCwd);
        $defaultReasoning = $config->ai?->defaultReasoning;
        if (null !== $defaultReasoning && '' !== $defaultReasoning) {
            return $defaultReasoning;
        }

        // 4. Fallback
        return 'medium';
    }

    // ──────────────────────────────────────────────
    //  Persistence (model)
    // ──────────────────────────────────────────────

    /**
     * Change the model for the current session.
     *
     * Persists the new default to home settings and current state to
     * session metadata, so the next session picks up the same model
     * and a resumed session restores it from metadata.
     *
     * @throws \RuntimeException If the model is not available
     */
    public function changeModel(
        AiModelReference $model,
        string $sessionId,
        string $projectCwd = '',
    ): void {
        $catalog = $this->catalog($projectCwd);

        if (null === $catalog) {
            throw new \RuntimeException('No AI configuration available.');
        }

        if (!$catalog->isAvailable($model)) {
            throw new \RuntimeException(\sprintf('Model "%s" is not available.', $model->toString()));
        }

        // Persist default to home settings
        $this->homeWriter->writeDefaultModel($model->toString());

        // Persist current state to session metadata
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'model' => $model->toString(),
            'model_provider' => $model->providerId,
            'model_name' => $model->modelName,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Persistence (reasoning)
    // ──────────────────────────────────────────────

    /**
     * Change the reasoning level for the current session.
     *
     * @throws \InvalidArgumentException If the level is not a valid reasoning level
     */
    public function changeReasoning(
        string $level,
        string $sessionId,
        string $projectCwd = '',
    ): void {
        if (!\in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid reasoning level "%s". Valid levels: %s.', $level, implode(', ', self::LEVELS)));
        }

        // Ensure home settings exist (triggers bootstrap from defaults on first launch)
        $this->configResolver->resolve($projectCwd);

        // Persist default to home settings
        $this->homeWriter->writeDefaultReasoning($level);

        // Persist current state to session metadata
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'reasoning' => $level,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Private
    // ──────────────────────────────────────────────

    /**
     * Resolve the catalog from the cached project config.
     *
     * @return HatfieldModelCatalog|null null when no AI section is configured
     */
    private function catalog(string $projectCwd): ?HatfieldModelCatalog
    {
        return $this->configResolver->resolve($projectCwd)->catalog;
    }
}
