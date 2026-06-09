<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Registers model/reasoning controls in the TUI.
 *
 * On registration:
 *  - /model command: open interactive model picker or select by ref
 *  - /model-favourites command: open favorites picker or toggle by ref
 *  - Ctrl+P listener cycles favorite models
 *  - Shift+Tab listener cycles reasoning levels
 *
 * Persists changes through ModelSelectionService and updates
 * TuiSessionState for immediate footer refresh.
 */
final class ModelControlListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly AppConfig $appConfig,
        private readonly ModelPickerController $pickerController,
        private readonly FavoritePickerController $favPickerController,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $tui = $context->tui;
        $screen = $context->screen;
        $modelService = $this->modelService;
        $appConfig = $this->appConfig;

        // Wire the picker controllers with references only available at register() time
        $this->pickerController->setRuntimeRefs($tui, $screen, $state);
        $this->favPickerController->setRuntimeRefs($tui, $screen, $state);

        // ── Register /model slash command (idempotent) ──
        $modelHandler = new ModelCommandHandler($modelService, $appConfig, $state, $this->pickerController, $this->favPickerController, $this->logger);
        if ($this->commandRegistry->has('model')) {
            $this->commandRegistry->setHandler('model', $modelHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'model',
                    aliases: ['m'],
                    description: 'Select the active AI model',
                    usage: '/model [provider/modelname]',
                    acceptsArguments: true,
                ),
                $modelHandler,
            );
        }

        // ── Register /model-favourites slash command ──
        $favCmdHandler = new ModelCommandHandler($modelService, $appConfig, $state, $this->pickerController, $this->favPickerController, $this->logger, isFavourites: true);
        if ($this->commandRegistry->has('model-favourites')) {
            $this->commandRegistry->setHandler('model-favourites', $favCmdHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'model-favourites',
                    aliases: ['model-favourite'],
                    description: 'Manage favourite AI models',
                    usage: '/model-favourites [provider/modelname]',
                    acceptsArguments: true,
                ),
                $favCmdHandler,
            );
        }

        // ── Register Ctrl+P — cycle favorite models ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state, $appConfig,
        ): void {
            // Ctrl+P is \x10
            if ("\x10" !== $event->getData()) {
                return;
            }
            $event->stopPropagation();

            $nextRef = $modelService->cycleFavoriteModel($state->sessionId);
            if (null === $nextRef) {
                return;
            }

            // Update footer state for immediate refresh (no persistent status entry)
            // Use display reasoning so non-thinking models reset footer color to off
            $state->footerModel = FooterStateInitializer::shortModelName($nextRef->toString());
            $state->footerReasoning = $modelService->getDisplayReasoning($state->sessionId);
            $state->contextWindow = FooterStateInitializer::resolveContextWindowForRef($appConfig, $nextRef);

            // For draft sessions, carry the model into the request so it is
            // used when the draft is promoted on first submit.  Without this,
            // SubmitListener reads $state->request?->model (null) and the
            // StartRunRequest carries no model, leaving the runtime to resolve
            // from stale AppConfig.
            if ('' === $state->sessionId) {
                // When $state->request is null (plain /new with no prior
                // --model), the empty-string prompt is just a carrier —
                // SubmitListener merges the real prompt from editor text
                // during draft promotion.
                $carrier = $state->request ?? new StartRunRequest(
                    prompt: '',
                    runId: '',
                    cwd: '',
                );
                $state->request = $carrier->withModel($nextRef->toString());
            }
        }, priority: 95);

        // ── Register Shift+Tab — cycle reasoning levels ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state,
        ): void {
            // Shift+Tab sends \x1b[Z
            if ("\x1b[Z" !== $event->getData()) {
                return;
            }
            $event->stopPropagation();

            // Only cycle when the current model supports thinking levels
            $nextLevel = $modelService->cycleReasoningForCurrentModel($state->sessionId);
            if (null === $nextLevel) {
                return;
            }

            // Update footer state for immediate refresh (no persistent status entry)
            $state->footerReasoning = $nextLevel;
        }, priority: 95);
    }
}
