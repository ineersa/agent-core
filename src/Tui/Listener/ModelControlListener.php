<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Layout\InputPriority;
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
 *
 * Command metadata is registered once per process via
 * {@see registerCatalog()}; each session binds fresh handlers wired to
 * the session's picker controllers.
 */
final class ModelControlListener implements TuiListenerRegistrar, SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $catalog->registerMetadata(new CommandMetadata(
            name: 'model',
            aliases: ['m'],
            description: 'Select the active AI model',
            usage: '/model [provider/modelname]',
            acceptsArguments: true,
        ));
        $catalog->registerMetadata(new CommandMetadata(
            name: 'model-favourites',
            aliases: ['model-favourite'],
            description: 'Manage favourite AI models',
            usage: '/model-favourites [provider/modelname]',
            acceptsArguments: true,
        ));
    }

    public function register(TuiRuntimeContext $context): void
    {
        $services = $context->sessionServices;
        $state = $context->state;
        $tui = $context->tui;
        $screen = $context->screen;
        $modelService = $this->modelService;
        $appConfig = $this->appConfig;
        $commandRegistry = $services->commandRegistry;
        $pickerController = $services->modelPicker;
        $favPickerController = $services->favoritePicker;

        // ── Bind /model slash command to the session handler ──
        $modelHandler = new ModelCommandHandler($modelService, $appConfig, $state, $pickerController, $favPickerController, $this->logger, $screen);
        $commandRegistry->bind('model', $modelHandler);

        // ── Bind /model-favourites slash command ──
        $favCmdHandler = new ModelCommandHandler($modelService, $appConfig, $state, $pickerController, $favPickerController, $this->logger, $screen, isFavourites: true);
        $commandRegistry->bind('model-favourites', $favCmdHandler);

        // ── Register Ctrl+P — cycle favorite models ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state, $appConfig, $screen,
        ): void {
            $keys = $screen->editorWidget()->getKeybindings();
            if (!$keys->matches($event->getData(), 'cycle_favorite_model')) {
                return;
            }
            $event->stopPropagation();

            $nextRef = $modelService->cycleFavoriteModel($state->sessionId);
            if (null === $nextRef) {
                return;
            }

            // Update footer state for immediate refresh.
            // getDisplayReasoning returns 'off' for non-thinking models so
            // the diamond/model colour resets correctly.
            FooterStateInitializer::applyModelSelection($state, $nextRef, $modelService, $appConfig);

            // Apply editor border colour matching the new reasoning level.
            $screen->applyEditorBorderColor($state->footerReasoning);

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
        }, priority: InputPriority::MODEL_CONTROL);

        // ── Register Shift+Tab — cycle reasoning levels ──
        $tui->addListener(static function (InputEvent $event) use (
            $modelService, $state, $screen,
        ): void {
            $keys = $screen->editorWidget()->getKeybindings();
            if (!$keys->matches($event->getData(), 'cycle_reasoning')) {
                return;
            }
            $event->stopPropagation();

            // Only cycle when the current model supports thinking levels.
            // When the model does not support thinking, the handler returns
            // null and we do nothing — no status entry, no footer colour
            // change, no misleading visual feedback.
            $nextLevel = $modelService->cycleReasoningForCurrentModel($state->sessionId);
            if (null === $nextLevel) {
                return;
            }

            // Update footer colour through the state field.
            // The FooterStateSegmentProvider reads this to colour the ◆
            // diamond and model name with the appropriate Thinking* token.
            $state->footerReasoning = $nextLevel;

            // Panel-only keyed status (setStatus does not touch the footer).
            $screen->setStatus('reasoning', $nextLevel);
            // Footer segments read footerReasoning; invalidate so colour updates this frame.
            $screen->refreshFooter();

            // Apply editor border colour matching the new reasoning level.
            $screen->applyEditorBorderColor($nextLevel);
        }, priority: InputPriority::MODEL_CONTROL);
    }
}
