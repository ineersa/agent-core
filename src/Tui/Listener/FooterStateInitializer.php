<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Utility\GitBranchDetector;

/**
 * Initialises TuiSessionState fields needed by the footer.
 *
 * Seeds model/reasoning/context window through ModelSelectionService
 * resolution tiers (same as runtime), including unavailable-default
 * fallback. Detects cwd (short: last 2 path segments) and git branch.
 * Sets session start time on first call.
 */
final readonly class FooterStateInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private AppConfig $appConfig,
        private ModelSelectionService $modelSelectionService,
    ) {
    }

    public function initialize(TuiSessionState $state): void
    {
        // Resolve through the same tiers as runtime startup, including the
        // unavailable-default fallback. Draft sessions still honour an explicit
        // pending StartRunRequest model/reasoning when no row exists yet.
        $explicitModel = null !== $state->request ? $state->request->model : null;
        $explicitReasoning = null !== $state->request ? $state->request->reasoning : null;

        $resolvedModel = $this->modelSelectionService->resolveInitialModel(
            $explicitModel,
            $state->sessionId,
        );
        $fullModel = null !== $resolvedModel ? $resolvedModel->toString() : '';

        if (null !== $resolvedModel) {
            $rawReasoning = $this->modelSelectionService->resolveInitialReasoning(
                $explicitReasoning,
                $state->sessionId,
            );
            $state->footerModel = self::shortModelName($fullModel);
            $state->footerReasoning = $this->modelSelectionService->clampReasoningLevel(
                $rawReasoning,
                $resolvedModel,
            );
            if (!$this->appConfig->catalog?->supportsThinkingLevels($resolvedModel)) {
                $state->footerReasoning = 'off';
            }
            $state->contextWindow = self::resolveContextWindowForRef($this->appConfig, $resolvedModel);
        } else {
            $state->footerModel = '';
            $state->footerReasoning = '';
            $state->contextWindow = 0;
        }

        if (0.0 === $state->sessionStartTime) {
            $state->sessionStartTime = microtime(true);
        }

        $cwd = getcwd();
        $state->cwd = false !== $cwd ? self::shortCwd($cwd) : '';
        $state->branch = GitBranchDetector::detect();
    }

    /**
     * Apply the three model-derived footer/state values for a selected model.
     *
     * Sets footerModel (short name), footerReasoning (display reasoning,
     * 'off' for non-thinking models), and contextWindow (catalog lookup).
     * Shared by ModelControlListener, ModelPickerController, and
     * ModelCommandHandler so every model change lands on the same values.
     * Border colour, persistence/send order, and screen refresh stay at
     * the call sites.
     */
    public static function applyModelSelection(
        TuiSessionState $state,
        AiModelReference $ref,
        ModelSelectionService $modelService,
        AppConfig $appConfig,
    ): void {
        $state->footerModel = self::shortModelName($ref->toString());
        $state->footerReasoning = $modelService->getDisplayReasoning($state->sessionId);
        $state->contextWindow = self::resolveContextWindowForRef($appConfig, $ref);
    }

    // ── Helpers ──

    public static function shortModelName(string $model): string
    {
        $slash = strpos($model, '/');
        if (false !== $slash) {
            return substr($model, $slash + 1);
        }

        return $model;
    }

    public static function shortCwd(string $path): string
    {
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));

        if (\count($parts) >= 2) {
            return $parts[\count($parts) - 2].'/'.$parts[\count($parts) - 1];
        }

        return $parts[0] ?? '';
    }

    /**
     * Resolve context window for an already-parsed model reference.
     *
     * Public so that callers doing model selection / footer update
     * (e.g. ModelControlListener, ModelCommandHandler,
     * ModelPickerController) can share the same catalog lookup
     * without duplicating the logic.
     */
    public static function resolveContextWindowForRef(AppConfig $appConfig, AiModelReference $ref): int
    {
        $catalog = $appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }
}
