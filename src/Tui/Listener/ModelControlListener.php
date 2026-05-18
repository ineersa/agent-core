<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Registers model/reasoning controls in the TUI.
 *
 * On registration:
 *  - /model command registered in the slash command registry
 *    (opens interactive SelectListWidget; textual subcommands remain)
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
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $tui = $context->tui;
        $screen = $context->screen;
        $modelService = $this->modelService;
        $appConfig = $this->appConfig;
        $pickerController = $this->pickerController;

        // Wire the picker controllers with references only available at register() time
        $this->pickerController->setRuntimeRefs($tui, $screen, $state);
        $this->favPickerController->setRuntimeRefs($tui, $screen, $state);

        // ── Register /model slash command (idempotent) ──
        $modelHandler = new ModelCommandHandler($modelService, $appConfig, $state, $this->pickerController, $this->favPickerController);
        if ($this->commandRegistry->has('model')) {
            $this->commandRegistry->setHandler('model', $modelHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'model',
                    aliases: ['m'],
                    description: 'Interactive model picker. /model opens picker (Enter to select, Esc to cancel, Ctrl+F to toggle favorite). /model fav opens favorites picker (Space to toggle * marker, Enter to save).',
                    usage: '/model | /model select <provider/modelname> | /model fav | /model fav <provider/modelname>',
                ),
                $modelHandler,
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
            $state->contextWindow = self::lookupContextWindow($appConfig, $nextRef);
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

    /**
     * Resolve context window for a model from the catalog.
     */
    private static function lookupContextWindow(AppConfig $appConfig, AiModelReference $ref): int
    {
        $catalog = $appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }
}
