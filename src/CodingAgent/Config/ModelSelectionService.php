<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;

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
     * Also clamps the persisted reasoning level to the new model's
     * supported levels so that a stale xhigh from a previous model
     * does not survive the switch for a high-only model.
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

        // Clamp reasoning to the new model's supported levels.
        // Without this, a previously-persisted xhigh survives for a model
        // that only supports up to high, causing the footer/API to show
        // xhigh with no reasoning-options effect (e.g. thinking.type, reasoning_effort).
        $currentReasoning = $this->getCurrentReasoning($sessionId);
        $clamped = $this->clampReasoningLevel($currentReasoning, $model);
        if ($clamped !== $currentReasoning) {
            $this->changeReasoning($clamped, $sessionId);
        }

        // Sync in-memory AppConfig (and its catalog) so current-process
        // consumers — footer state initializer, model resolver, Ctrl+P
        // cycling — see the updated default immediately. Without this,
        // AppConfig holds the value from process start while
        // HomeSettingsWriter has already mutated the YAML on disk, causing
        // a visibility gap on /new session switches.
        $this->syncAppConfigAi(defaultModel: $model->toString());
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

        // Sync in-memory AppConfig (and its catalog) so current-process
        // consumers see the updated reasoning default immediately.
        $this->syncAppConfigAi(defaultReasoning: $level);
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

        // Sync in-memory AppConfig (and its catalog) so current-process
        // consumers see the updated favorites list immediately.
        $this->syncAppConfigAi(favoriteModels: $current);
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
     *
     * Clamps to the model's supported levels so that a persisted xhigh
     * becomes high when the active model only supports up to high.
     */
    public function getDisplayReasoning(string $sessionId): string
    {
        return $this->resolver->getDisplayReasoning($sessionId);
    }

    /**
     * Clamp a reasoning level to the model's supported levels.
     *
     * When the given level is not in the model's thinking_level_map,
     * returns the highest supported level instead.
     */
    public function clampReasoningLevel(string $level, AiModelReference $model): string
    {
        return $this->resolver->clampReasoningLevel($level, $model);
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
    //  In-memory sync
    // ──────────────────────────────────────────────

    /**
     * Sync in-memory AppConfig (and rebuild its catalog) after a mutation.
     *
     * AppConfig is built once at process start. When changeModel / changeReasoning /
     * toggleFavorite persist mutations to the home YAML via HomeSettingsWriter,
     * the in-memory AppConfig (and its HatfieldModelCatalog) still hold the
     * pre-mutation values. This causes a visibility gap: Ctrl+P changes the
     * default, but /new (which reads AppConfig) still sees the old default.
     *
     * This helper replaces AppConfig::$ai with a new AiConfig carrying the
     * updated fields and rebuilds AppConfig::$catalog so that
     * defaultModelReference() and provider/favorites lookups return fresh data.
     *
     * @param string|null       $defaultModel     Set to override the default model
     * @param string|null       $defaultReasoning Set to override the default reasoning
     * @param list<string>|null $favoriteModels   Set to override the favorites list
     */
    private function syncAppConfigAi(
        ?string $defaultModel = null,
        ?string $defaultReasoning = null,
        ?array $favoriteModels = null,
    ): void {
        $ai = $this->appConfig->ai;
        if (null === $ai) {
            return;
        }

        $this->appConfig->ai = new AiConfig(
            defaultModel: $defaultModel ?? $ai->defaultModel,
            defaultReasoning: $defaultReasoning ?? $ai->defaultReasoning,
            providers: $ai->providers,
            favoriteModels: $favoriteModels ?? $ai->favoriteModels,
        );
        $this->appConfig->catalog = new HatfieldModelCatalog($this->appConfig->ai);
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
