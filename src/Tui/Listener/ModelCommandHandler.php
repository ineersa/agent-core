<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Psr\Log\LoggerInterface;

/**
 * Handles /model and /model-favourites slash commands.
 *
 * Lives in TuiListener (not TuiCommand) because it needs
 * ModelSelectionService from CodingAgent/Config, which TuiCommand
 * cannot import per deptrac rules.
 *
 * /model: no args opens the interactive model picker; with a
 * provider/modelname ref selects the model directly.
 *
 * /model-favourites: no args opens the favorites picker; with a
 * provider/modelname ref toggles favorite status.
 *
 * Updates TuiSessionState fields for immediate footer refresh after
 * model/reasoning changes.
 */
final class ModelCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly ModelSelectionService $modelService,
        private readonly AppConfig $appConfig,
        private readonly TuiSessionState $state,
        private readonly ModelPickerController $pickerController,
        private readonly FavoritePickerController $favPickerController,
        private readonly LoggerInterface $logger,
        private readonly bool $isFavourites = false,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $args = trim($command->args);

        if ($this->isFavourites) {
            return $this->handleFavourites($args);
        }

        return $this->handleModel($args);
    }

    // ── /model ──────────────────────────────────────────────────────

    private function handleModel(string $args): CommandResult
    {
        if ('' === $args) {
            $this->pickerController->open();

            if (!$this->pickerController->isOpen()) {
                return $this->buildModelListMessage();
            }

            return new NoOp();
        }

        return $this->selectModel($args);
    }

    // ── /model-favourites ────────────────────────────────────────────

    private function handleFavourites(string $args): CommandResult
    {
        if ('' === $args) {
            $this->favPickerController->open();

            if ($this->favPickerController->isOpen()) {
                return new NoOp();
            }

            return $this->buildFavoritesListMessage();
        }

        return $this->toggleFavorite($args);
    }

    // ── Model selection ─────────────────────────────────────────────

    private function selectModel(string $modelSpec): CommandResult
    {
        $ref = AiModelReference::tryParse($modelSpec);
        if (null === $ref) {
            return new TranscriptMessage(
                \sprintf(
                    'Invalid model reference: "%s". Use the format provider/modelname, e.g. deepseek/deepseek-v4-pro.',
                    $modelSpec,
                ),
                'system',
                'muted',
            );
        }

        try {
            $this->modelService->changeModel($ref, $this->state->sessionId);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Failed to change model', [
                'exception' => $e,
                'model' => $ref->toString(),
            ]);

            return new TranscriptMessage($e->getMessage(), 'system', 'muted');
        }

        $this->state->footerModel = FooterStateInitializer::shortModelName(
            $ref->providerId.'/'.$ref->modelName,
        );
        $this->state->footerReasoning = $this->modelService->getDisplayReasoning($this->state->sessionId);
        $this->state->contextWindow = FooterStateInitializer::resolveContextWindowForRef($this->appConfig, $ref);

        return new TranscriptMessage(
            \sprintf('Model changed to %s.', $ref->toString()),
            'system',
        );
    }

    // ── Favorite toggling ───────────────────────────────────────────

    private function toggleFavorite(string $modelSpec): CommandResult
    {
        $ref = AiModelReference::tryParse($modelSpec);
        if (null === $ref) {
            return new TranscriptMessage(
                \sprintf(
                    'Invalid model reference: "%s". Use the format provider/modelname.',
                    $modelSpec,
                ),
                'system',
                'muted',
            );
        }

        try {
            $wasFavorite = $this->modelService->isFavorite($ref);
            $this->modelService->toggleFavorite($ref);

            if ($wasFavorite) {
                return new TranscriptMessage(
                    \sprintf('Removed %s from favourites.', $ref->toString()),
                    'system',
                );
            }

            return new TranscriptMessage(
                \sprintf('Added %s to favourites.', $ref->toString()),
                'system',
            );
        } catch (\RuntimeException $e) {
            $this->logger->warning('Failed to toggle favourite', [
                'exception' => $e,
                'model' => $ref->toString(),
            ]);

            return new TranscriptMessage($e->getMessage(), 'system', 'muted');
        }
    }

    // ── Model list formatting ───────────────────────────────────────

    private function buildModelListMessage(): TranscriptMessage
    {
        $fetchError = null;

        try {
            $ordered = $this->modelService->getOrderedModels();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get ordered models', [
                'exception' => $e,
            ]);
            $ordered = [];
            $fetchError = $e;
        }

        if ([] === $ordered) {
            if (null !== $fetchError) {
                return new TranscriptMessage(
                    \sprintf('Failed to load AI models: %s. Check your AI settings in .hatfield/settings.yaml.', $fetchError->getMessage()),
                    'system',
                    'muted',
                );
            }

            return new TranscriptMessage(
                'No AI models configured. Check your AI settings in .hatfield/settings.yaml.',
                'system',
                'muted',
            );
        }

        $favorites = $this->modelService->getFavoriteModels();
        $favSet = array_flip($favorites);
        $currentModel = $this->modelService->getCurrentModel($this->state->sessionId);
        $currentStr = null !== $currentModel ? $currentModel->toString() : null;

        $lines = ['Available models:', ''];

        foreach ($ordered as $i => $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $isCurrent = $refStr === $currentStr;

            $n = \sprintf('%2d.', $i + 1);
            $star = $isFav ? '★' : ' ';
            $current = $isCurrent ? ' (current)' : '';

            $lines[] = \sprintf(
                '  %s %s %s%s',
                $isCurrent ? '❯' : ' ',
                $n,
                $star,
                $refStr.$current,
            );
        }

        $lines[] = '';
        $lines[] = 'Type /model <provider/modelname> to select a model.';
        $lines[] = 'Type /model-favourites <provider/modelname> to toggle a favourite.';

        return new TranscriptMessage(implode("\n", $lines), 'system');
    }

    // ── Favorites list formatting ────────────────────────────────────

    /**
     * Build a textual favourites list (fallback when picker can't open).
     */
    private function buildFavoritesListMessage(): TranscriptMessage
    {
        $all = $this->modelService->getAvailableModels();
        $favorites = $this->modelService->getFavoriteModels();
        $favSet = array_flip($favorites);

        if ([] === $all) {
            return new TranscriptMessage(
                'No AI models configured. Check your AI settings in .hatfield/settings.yaml.',
                'system',
                'muted',
            );
        }

        $lines = ['Favourite models (* = favourite):', ''];
        foreach ($all as $i => $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $marker = $isFav ? '*' : ' ';
            $lines[] = \sprintf('  %2d. %s %s', $i + 1, $marker, $refStr);
        }
        $lines[] = '';
        $lines[] = 'Type /model-favourites <provider/modelname> to toggle a favourite.';

        return new TranscriptMessage(implode("\n", $lines), 'system');
    }
}
