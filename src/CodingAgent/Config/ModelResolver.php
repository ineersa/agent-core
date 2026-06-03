<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;

/**
 * Read-only model and reasoning resolution with four-tier priority.
 *
 * Model resolution priority:
 *  1. explicit request (CLI --model, StartRunRequest.model)
 *  2. session metadata (model key in the hatfield_session DB table)
 *  3. Hatfield ai.default_model
 *  4. first available configured model
 *
 * Reasoning mirrors model selection, falling back to medium.
 *
 * This service has no mutation or persistence logic — it only resolves.
 * It holds a SessionMetadataStore reference for Tier 2 metadata lookup.
 *
 * Purely a CodingAgent config service; does not import AgentCore, Tui,
 * HttpFoundation, or FrameworkBundle.
 */
final class ModelResolver
{
    /** Valid reasoning levels. */
    public const LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly AppConfig $appConfig,
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
    //  Catalog
    // ──────────────────────────────────────────────

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

    /**
     * Get the currently active model for the session.
     */
    public function getCurrentModel(string $sessionId): ?AiModelReference
    {
        return $this->resolveInitialModel(null, $sessionId);
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
     * otherwise returns the persisted current reasoning.
     */
    public function getDisplayReasoning(string $sessionId): string
    {
        if (!$this->supportsThinkingLevelsForSession($sessionId)) {
            return 'off';
        }

        return $this->getCurrentReasoning($sessionId);
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
     * Get the reasoning levels supported by the current session's model.
     *
     * Returns the keys from the model's thinking_level_map plus 'off' as
     * the first entry. Falls back to the global {@see LEVELS} constant
     * when no model is resolved.
     *
     * When the model has a thinking_level_map, only keys present in the map
     * are returned (e.g. z.ai models that omit xhigh cycle only through
     * off→minimal→low→medium→high, never exposing unsupported levels).
     * This prevents the UI from offering levels the model cannot honour.
     *
     * @return list<string>
     */
    public function getSupportedReasoningLevels(string $sessionId): array
    {
        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return self::LEVELS;
        }

        $model = $this->getCurrentModel($sessionId);
        if (null === $model) {
            return self::LEVELS;
        }

        $def = $catalog->getModel($model);
        if (null === $def || [] === $def->thinkingLevelMap) {
            return ['off'];
        }

        $levels = array_keys($def->thinkingLevelMap);
        if (!\in_array('off', $levels, true)) {
            array_unshift($levels, 'off');
        }

        return $levels;
    }

    // ──────────────────────────────────────────────
    //  Cycling helpers
    // ──────────────────────────────────────────────

    /**
     * Cycle to the next reasoning level.
     */
    public function cycleReasoning(string $currentLevel): string
    {
        $pos = array_search($currentLevel, self::LEVELS, true);

        if (false === $pos) {
            return self::LEVELS[0];
        }

        $nextIdx = ($pos + 1) % \count(self::LEVELS);

        return self::LEVELS[$nextIdx];
    }
}
