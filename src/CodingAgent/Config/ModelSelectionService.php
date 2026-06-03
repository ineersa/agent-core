<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;

/**
 * Central model/reasoning selection with four-tier priority and persistence.
 *
 * Coordinates pure resolution ({@see ModelResolver}) with write/persist
 * ({@see ModelSettingsPersister}) and owns favorites management with
 * in-process caching.
 *
 * Public API is identical to the original monolithic version — all callers
 * unchanged.  Internally delegates read methods to {@see ModelResolver},
 * write methods to {@see ModelSettingsPersister}, and keeps favorites
 * locally (toggle + favRaw cache).
 */
final class ModelSelectionService
{
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
        private readonly ModelResolver $resolver,
        private readonly ModelSettingsPersister $persister,
    ) {
    }

    // ──────────────────────────────────────────────
    //  Model resolution (delegated)
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial model for a session.
     */
    public function resolveInitialModel(
        ?string $explicitModel = null,
        string $sessionId = '',
    ): ?AiModelReference {
        return $this->resolver->resolveInitialModel($explicitModel, $sessionId);
    }

    /**
     * Get all available model references.
     *
     * @return list<AiModelReference>
     */
    public function getAvailableModels(): array
    {
        return $this->resolver->getAvailableModels();
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution (delegated)
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial reasoning level for a session.
     */
    public function resolveInitialReasoning(
        ?string $explicitReasoning = null,
        string $sessionId = '',
    ): string {
        return $this->resolver->resolveInitialReasoning($explicitReasoning, $sessionId);
    }

    // ──────────────────────────────────────────────
    //  Persistence (model) — validates then delegates
    // ──────────────────────────────────────────────

    /**
     * Change the model for the current session.
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

        $this->persister->persistModel($model->toString(), $model->providerId, $model->modelName, $sessionId);
    }

    // ──────────────────────────────────────────────
    //  Persistence (reasoning) — delegates (validation inside persister)
    // ──────────────────────────────────────────────

    /**
     * Change the reasoning level for the current session.
     *
     * @throws \InvalidArgumentException If the level is not a valid reasoning level
     */
    public function changeReasoning(string $level, string $sessionId): void
    {
        $this->persister->persistReasoning($level, $sessionId);
    }

    // ──────────────────────────────────────────────
    //  Favorites
    // ──────────────────────────────────────────────

    /**
     * Get the persisted favorite model refs (provider/modelname strings).
     *
     * Only returns favorites that are actually available in the catalog.
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

        $favModels = [];
        $rest = [];

        foreach ($all as $ref) {
            if (isset($favSet[$ref->toString()])) {
                $favModels[] = $ref;
            } else {
                $rest[] = $ref;
            }
        }

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
            unset($current[$pos]);
            $current = array_values($current);
        } else {
            $current[] = $modelStr;
        }

        $this->favRaw = $current;

        $this->persister->persistFavoriteModels($current);
    }

    // ──────────────────────────────────────────────
    //  Cycling helpers
    // ──────────────────────────────────────────────

    /**
     * Get the currently active model for the session.
     */
    public function getCurrentModel(string $sessionId): ?AiModelReference
    {
        return $this->resolver->getCurrentModel($sessionId);
    }

    /**
     * Cycle to the next favorite model and persist it.
     */
    public function cycleFavoriteModel(string $sessionId): ?AiModelReference
    {
        $favorites = $this->getFavoriteModels();
        if ([] === $favorites) {
            return null;
        }

        $current = $this->getCurrentModel($sessionId);
        $currentStr = null !== $current ? $current->toString() : null;

        $pos = null !== $currentStr ? array_search($currentStr, $favorites, true) : false;

        if (false === $pos) {
            $nextStr = $favorites[0];
        } else {
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
     */
    public function cycleReasoning(string $currentLevel): string
    {
        return $this->resolver->cycleReasoning($currentLevel);
    }

    /**
     * Cycle reasoning for the current model.
     */
    public function cycleReasoningForCurrentModel(string $sessionId): ?string
    {
        if (!$this->supportsThinkingLevelsForSession($sessionId)) {
            return null;
        }

        $current = $this->getCurrentReasoning($sessionId);
        $levels = $this->getSupportedReasoningLevels($sessionId);

        $pos = array_search($current, $levels, true);
        if (false === $pos) {
            $nextLevel = $levels[0];
        } else {
            $nextIdx = ($pos + 1) % \count($levels);
            $nextLevel = $levels[$nextIdx];
        }

        $this->changeReasoning($nextLevel, $sessionId);

        return $nextLevel;
    }

    /**
     * Does the current session's model support reasoning-level cycling?
     */
    public function supportsThinkingLevelsForSession(string $sessionId): bool
    {
        return $this->resolver->supportsThinkingLevelsForSession($sessionId);
    }

    /**
     * Get the currently active reasoning level for the session.
     */
    public function getCurrentReasoning(string $sessionId): string
    {
        return $this->resolver->getCurrentReasoning($sessionId);
    }

    /**
     * Get the effective reasoning level for display.
     */
    public function getDisplayReasoning(string $sessionId): string
    {
        return $this->resolver->getDisplayReasoning($sessionId);
    }

    /**
     * Get the reasoning levels supported by the current session's model.
     *
     * @return list<string>
     */
    public function getSupportedReasoningLevels(string $sessionId): array
    {
        return $this->resolver->getSupportedReasoningLevels($sessionId);
    }

    // ──────────────────────────────────────────────
    //  Favorites helpers
    // ──────────────────────────────────────────────

    /**
     * Get the raw favorite model refs (provider/modelname strings).
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
