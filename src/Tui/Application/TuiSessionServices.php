<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;

/**
 * Readonly holder of the per-session TUI services composed by
 * {@see TuiSessionCompositionFactory} for one loop iteration.
 *
 * Contains only services that were previously shared singletons with
 * late per-iteration rebinding; every instance here is fresh per
 * session and constructor-valid (usable immediately).  Shared
 * stateless registrars consume them through
 * {@see \Ineersa\Tui\Runtime\TuiRuntimeContext::$sessionServices}.
 */
final readonly class TuiSessionServices
{
    public function __construct(
        public TuiSessionSwitchService $switch,
        public SlashCommandRegistry $commandRegistry,
        public SubmissionRouter $submissionRouter,
        public QuestionCoordinator $questionCoordinator,
        public QuestionController $questionController,
        public PromptHistory $promptHistory,
        public ModelPickerController $modelPicker,
        public FavoritePickerController $favoritePicker,
        public SessionPickerController $sessionPicker,
        public HistoryPickerController $historyPicker,
        public SubagentLivePickerController $subagentLivePicker,
        public TuiRuntimeEventApplier $parentEventApplier,
        public RuntimeEventPoller $parentPoller,
        public SubagentLiveChildViewPoller $childPoller,
    ) {
    }
}
