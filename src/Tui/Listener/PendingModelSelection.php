<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Applies model/reasoning selection through ModelSelectionService and keeps
 * draft pending StartRunRequest intent aligned before first submit.
 *
 * Numeric sessions persist via settings + session metadata. Draft sessions
 * have no row yet, so the chosen model/reasoning must ride the pending
 * request until SubmitListener merges it into the first start().
 */
final class PendingModelSelection
{
    private function __construct()
    {
    }

    public static function updateModel(
        ModelSelectionService $modelService,
        AiModelReference $model,
        TuiSessionState $state,
    ): void {
        $modelService->changeModel($model, $state->sessionId);

        if ('' !== $state->sessionId) {
            return;
        }

        $carrier = $state->request ?? new StartRunRequest(
            prompt: '',
            runId: '',
            cwd: '',
        );
        $carrier = $carrier->withModel($model->toString());
        $reasoning = $modelService->getDisplayReasoning($state->sessionId);
        $state->request = $carrier->withReasoning('' !== $reasoning ? $reasoning : null);
    }

    public static function updateReasoning(
        ModelSelectionService $modelService,
        string $level,
        TuiSessionState $state,
    ): void {
        $modelService->changeReasoning($level, $state->sessionId);

        if ('' !== $state->sessionId) {
            return;
        }

        $carrier = $state->request ?? new StartRunRequest(
            prompt: '',
            runId: '',
            cwd: '',
        );
        $state->request = $carrier->withReasoning($level);
    }
}
