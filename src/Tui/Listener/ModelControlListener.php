<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Registers model/reasoning controls in the TUI.
 *
 * On registration:
 *  - /model command registered in the slash command registry
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
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $tui = $context->tui;
        $screen = $context->screen;
        $modelService = $this->modelService;
        $appConfig = $this->appConfig;

        // ── Register /model slash command ──
        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'model',
                aliases: ['m'],
                description: 'List, select, or manage favorite AI models',
                usage: '/model [select <provider/modelname> | fav [<provider/modelname>]]',
            ),
            new ModelCommandHandler($modelService, $appConfig, $state),
        );

        // ── Register Ctrl+P — cycle favorite models ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state, $screen, $appConfig,
        ): void {
            // Ctrl+P is \x10
            if ("\x10" !== $event->getData()) {
                return;
            }
            $event->stopPropagation();

            $nextRef = $modelService->cycleFavoriteModel($state->sessionId);
            if (null === $nextRef) {
                $screen->setStatus('model', 'No favorites configured.');
                $screen->refresh();

                return;
            }

            // Update footer state for immediate refresh
            $state->footerModel = FooterStateInitializer::shortModelName($nextRef->toString());
            $state->footerReasoning = $modelService->getCurrentReasoning($state->sessionId);
            $state->contextWindow = self::lookupContextWindow($appConfig, $nextRef);

            $screen->setStatus('model', 'Model: '.$nextRef->toString());
            $screen->refresh();
        }, priority: 95);

        // ── Register Shift+Tab — cycle reasoning levels ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state, $screen,
        ): void {
            // Shift+Tab sends \x1b[Z
            if ("\x1b[Z" !== $event->getData()) {
                return;
            }
            $event->stopPropagation();

            $current = $modelService->getCurrentReasoning($state->sessionId);
            $nextLevel = $modelService->cycleReasoning($current);

            // Persist
            $modelService->changeReasoning($nextLevel, $state->sessionId);

            // Update footer state
            $state->footerReasoning = $nextLevel;

            $screen->setStatus('reasoning', 'Reasoning: '.$nextLevel);
            $screen->refresh();
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
