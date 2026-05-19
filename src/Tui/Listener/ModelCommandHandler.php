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

/**
 * Handles /model slash commands: interactive picker, selection, and favorites.
 *
 * Lives in TuiListener (not TuiCommand) because it needs
 * ModelSelectionService from CodingAgent/Config, which TuiCommand
 * cannot import per deptrac rules.
 *
 * /model with no args opens an interactive selectable list via
 * {@see ModelPickerController}.  Textual subcommands (/model select,
 * /model fav) remain available as keyboard-free fallbacks.
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
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $args = trim($command->args);

        // /model (no args) → open interactive picker
        if ('' === $args) {
            $this->pickerController->open();

            // If the picker couldn't open (e.g. no TUI refs in tests),
            // fall back to the textual list.
            if (!$this->pickerController->isOpen()) {
                return $this->buildModelListMessage();
            }

            return new NoOp();
        }

        // Parse subcommand or provider/model reference
        $parts = explode(' ', $args, 2);
        $first = $parts[0];
        $rest = $parts[1] ?? '';

        return match ($first) {
            'select', 'sel' => $this->selectModel($rest),
            'fav' => $this->toggleFavoriteCommand($rest),
            default => $this->selectModel($args), // try as direct provider/modelname
        };
    }

    // ── Subcommand: /model select <provider/model> ──

    private function selectModel(string $modelSpec): CommandResult
    {
        if ('' === $modelSpec) {
            return new TranscriptMessage(
                "Usage: /model select <provider/modelname>\n\nType /model to see available models.",
                'system',
                'muted',
            );
        }

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
            return new TranscriptMessage($e->getMessage(), 'system', 'muted');
        }

        // Update footer state for immediate refresh — reset to off when model doesn't support thinking
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

    // ── Subcommand: /model fav <provider/model> ──

    private function toggleFavoriteCommand(string $modelSpec): CommandResult
    {
        if ('' === $modelSpec) {
            // Open interactive favorites picker
            $this->favPickerController->open();

            if ($this->favPickerController->isOpen()) {
                return new NoOp();
            }

            // Fallback: textual list when TUI refs not available (tests, etc.)
            return $this->buildFavoritesListMessage();
        }

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
                    \sprintf('Removed %s from favorites.', $ref->toString()),
                    'system',
                );
            }

            return new TranscriptMessage(
                \sprintf('Added %s to favorites.', $ref->toString()),
                'system',
            );
        } catch (\RuntimeException $e) {
            return new TranscriptMessage($e->getMessage(), 'system', 'muted');
        }
    }

    // ── Model list formatting ──

    private function buildModelListMessage(): TranscriptMessage
    {
        try {
            $ordered = $this->modelService->getOrderedModels();
        } catch (\Throwable) {
            $ordered = [];
        }

        if ([] === $ordered) {
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

        $favCount = 0;

        foreach ($ordered as $i => $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $isCurrent = $refStr === $currentStr;

            $n = \sprintf('%2d.', $i + 1);
            $star = $isFav ? '★' : ' ';
            $current = $isCurrent ? ' (current)' : '';

            if ($isFav) {
                ++$favCount;
            }

            $lines[] = \sprintf(
                '  %s %s %s%s',
                $isCurrent ? '❯' : ' ',
                $n,
                $star,
                $refStr.$current,
            );
        }

        $lines[] = '';
        $lines[] = 'Type /model select <provider/modelname> to select a model.';
        $lines[] = 'Type /model fav <provider/modelname> to toggle favorite.';
        $lines[] = 'Press Ctrl+P to cycle favorite models.';
        $lines[] = 'Press Shift+Tab to cycle reasoning levels.';

        return new TranscriptMessage(implode("\n", $lines), 'system');
    }

    // ── Helpers ──

    /**
     * Build a textual favorites list (fallback when picker can't open).
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

        $lines = ['Favorite models (* = favorite):', ''];
        foreach ($all as $i => $ref) {
            $refStr = $ref->toString();
            $isFav = isset($favSet[$refStr]);
            $marker = $isFav ? '*' : ' ';
            $lines[] = \sprintf('  %2d. %s %s', $i + 1, $marker, $refStr);
        }
        $lines[] = '';
        $lines[] = 'Type /model fav <provider/modelname> to toggle a favorite.';
        $lines[] = 'Type /model fav (no args) to open the interactive picker.';

        return new TranscriptMessage(implode("\n", $lines), 'system');
    }
}
