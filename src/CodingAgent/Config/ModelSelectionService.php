<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;

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

    /**
     * In-process cache of the raw favorite_models list (provider/modelname strings).
     *
     * When null (uninitialized), getFavoriteRawList() reads from AppConfig.
     * After toggleFavorite() mutates the list, this cache is authoritative for
     * the remainder of the process lifetime so that callers see the toggle
     * immediately instead of waiting for an AppConfig rebuild.
     *
     * @var list<string>|null
     */
    private ?array $favRaw = null;

    public function __construct(
        private readonly AppConfig $appConfig,
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
     *
     * @return AiModelReference|null Null only if no models are configured at all
     */
    public function resolveInitialModel(
        ?string $explicitModel = null,
        string $sessionId = '',
    ): ?AiModelReference {
        $catalog = $this->appConfig->catalog;
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
     * Get all available model references.
     *
     * @return list<AiModelReference>
     */
    public function getAvailableModels(): array
    {
        $catalog = $this->appConfig->catalog;

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
     *
     * @return string A reasoning level from {@see LEVELS}
     */
    public function resolveInitialReasoning(
        ?string $explicitReasoning = null,
        string $sessionId = '',
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
        $defaultReasoning = $this->appConfig->ai?->defaultReasoning;
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
    public function changeModel(AiModelReference $model, string $sessionId): void
    {
        $catalog = $this->appConfig->catalog;
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
    public function changeReasoning(string $level, string $sessionId): void
    {
        if (!\in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid reasoning level "%s". Valid levels: %s.', $level, implode(', ', self::LEVELS)));
        }

        // Persist default to home settings
        $this->homeWriter->writeDefaultReasoning($level);

        // Persist current state to session metadata
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'reasoning' => $level,
        ]);
    }

    /**
     * Get the persisted favorite model refs (provider/modelname strings).
     *
     * Only returns favorites that are actually available in the catalog.
     *
     * Consults the in-process cache when available so that toggleFavorite()
     * is immediately visible to callers in the same process.
     *
     * @return list<string>
     */
    public function getFavoriteModels(): array
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return [];
        }

        $raw = $this->getFavoriteRawList();
        if ([] === $raw) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (string $ref): bool => $catalog->isAvailable($ref),
        ));
    }

    /**
     * Get all available models, with favorites first.
     *
     * @return list<AiModelReference>
     */
    public function getOrderedModels(): array
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return [];
        }

        $all = $catalog->allModels();
        $favorites = $this->getFavoriteModels();

        if ([] === $favorites) {
            return $all;
        }

        $favSet = array_flip($favorites);

        // Partition into favorites and non-favorites
        $favModels = [];
        $rest = [];

        foreach ($all as $ref) {
            if (isset($favSet[$ref->toString()])) {
                $favModels[] = $ref;
            } else {
                $rest[] = $ref;
            }
        }

        // Favorites in the order they appear in ai.favorite_models
        usort($favModels, static function (AiModelReference $a, AiModelReference $b) use ($favorites): int {
            $posA = array_search($a->toString(), $favorites, true);
            $posB = array_search($b->toString(), $favorites, true);

            return (false === $posA ? \PHP_INT_MAX : $posA) <=> (false === $posB ? \PHP_INT_MAX : $posB);
        });

        return array_merge($favModels, $rest);
    }

    /**
     * Is the given model ref a favorite?
     */
    public function isFavorite(AiModelReference|string $model): bool
    {
        $modelStr = \is_string($model) ? $model : $model->toString();

        return \in_array($modelStr, $this->getFavoriteModels(), true);
    }

    /**
     * Toggle a model as favorite (add if absent, remove if present).
     *
     * Persists to home settings AND updates the in-process cache so that
     * getFavoriteModels(), isFavorite(), getOrderedModels(), and
     * cycleFavoriteModel() reflect the change immediately in the same
     * process without requiring an AppConfig rebuild.
     *
     * @throws \RuntimeException If the model is not available
     */
    public function toggleFavorite(AiModelReference $model): void
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            throw new \RuntimeException('No AI configuration available.');
        }
        if (!$catalog->isAvailable($model)) {
            throw new \RuntimeException(\sprintf('Model "%s" is not available.', $model->toString()));
        }

        $current = $this->getFavoriteRawList();
        $modelStr = $model->toString();
        $pos = array_search($modelStr, $current, true);

        if (false !== $pos) {
            // Remove
            unset($current[$pos]);
            $current = array_values($current);
        } else {
            // Add to end
            $current[] = $modelStr;
        }

        // Update in-process cache before persisting to disk so that
        // subsequent reads in this process see the change immediately.
        $this->favRaw = $current;

        $this->homeWriter->writeFavoriteModels($current);
    }

    // ──────────────────────────────────────────────
    //  Cycling helpers
    // ──────────────────────────────────────────────

    /**
     * Get the currently active model for the session.
     *
     * Resolves through session metadata → home default → first available.
     */
    public function getCurrentModel(string $sessionId): ?AiModelReference
    {
        return $this->resolveInitialModel(null, $sessionId);
    }

    /**
     * Cycle to the next favorite model and persist it.
     *
     * Returns the newly selected model reference, or null if no favorites exist.
     */
    public function cycleFavoriteModel(string $sessionId): ?AiModelReference
    {
        $favorites = $this->getFavoriteModels();
        if ([] === $favorites) {
            return null;
        }

        $current = $this->getCurrentModel($sessionId);
        $currentStr = null !== $current ? $current->toString() : null;

        // Find current position in favorites
        $pos = null !== $currentStr ? array_search($currentStr, $favorites, true) : false;

        // If current is not in favorites, start from beginning
        if (false === $pos) {
            $nextStr = $favorites[0];
        } else {
            // Cycle to next, wrapping around
            $nextIdx = ($pos + 1) % \count($favorites);
            $nextStr = $favorites[$nextIdx];
        }

        $nextRef = AiModelReference::tryParse($nextStr);
        if (null === $nextRef) {
            return null;
        }

        $this->changeModel($nextRef, $sessionId);

        return $nextRef;
    }

    /**
     * Cycle to the next reasoning level.
     *
     * Returns the new level string.
     */
    public function cycleReasoning(string $currentLevel): string
    {
        $pos = array_search($currentLevel, self::LEVELS, true);

        if (false === $pos) {
            // Unknown level — start from beginning
            return self::LEVELS[0];
        }

        $nextIdx = ($pos + 1) % \count(self::LEVELS);

        return self::LEVELS[$nextIdx];
    }

    /**
     * Cycle reasoning for the current model, but only if the model supports
     * thinking levels. Does NOT persist or change state when unsupported.
     *
     * Returns the new level on success, or null when thinking is not supported
     * for the current model.
     */
    public function cycleReasoningForCurrentModel(string $sessionId): ?string
    {
        if (!$this->supportsThinkingLevelsForSession($sessionId)) {
            return null;
        }

        $current = $this->getCurrentReasoning($sessionId);
        $nextLevel = $this->cycleReasoning($current);
        $this->changeReasoning($nextLevel, $sessionId);

        return $nextLevel;
    }

    /**
     * Does the current session's model support reasoning-level cycling?
     */
    public function supportsThinkingLevelsForSession(string $sessionId): bool
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return false;
        }

        $model = $this->getCurrentModel($sessionId);

        return null !== $model && $catalog->supportsThinkingLevels($model);
    }

    /**
     * Get the currently active reasoning level for the session.
     */
    public function getCurrentReasoning(string $sessionId): string
    {
        return $this->resolveInitialReasoning(null, $sessionId);
    }

    /**
     * Get the effective reasoning level for display (footer color, UI indicator).
     *
     * Returns 'off' when the current model does not support thinking levels;
     * otherwise returns the persisted current reasoning.  This prevents stale
     * high/xhigh coloring from lingering after switching to a non-thinking model
     * (e.g. llama.cpp).
     */
    public function getDisplayReasoning(string $sessionId): string
    {
        if (!$this->supportsThinkingLevelsForSession($sessionId)) {
            return 'off';
        }

        return $this->getCurrentReasoning($sessionId);
    }

    // ──────────────────────────────────────────────
    //  Favorites
    // ──────────────────────────────────────────────

    /**
     * Get the raw favorite model refs (provider/modelname strings).
     *
     * Prefers the in-process cache when it has been populated by a prior
     * toggleFavorite() call; otherwise reads from immutable AppConfig.
     *
     * @return list<string>
     */
    private function getFavoriteRawList(): array
    {
        if (null !== $this->favRaw) {
            return $this->favRaw;
        }

        $ai = $this->appConfig->ai;

        return (null !== $ai) ? $ai->favoriteModels : [];
    }
}
