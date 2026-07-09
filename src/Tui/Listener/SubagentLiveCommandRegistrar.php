<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

final class SubagentLiveCommandRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly SubagentLivePickerController $pickerController,
        private readonly RuntimeQuestionEventHandler $runtimeQuestionEventHandler,
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $runtimeQuestionEventHandler = $this->runtimeQuestionEventHandler;
        $questionCoordinator = $this->questionCoordinator;
        $questionController = $this->questionController;

        $onHumanInputRequested = static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
            $runtimeQuestionEventHandler->handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
        };
        $onToolQuestionRequested = static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
            $runtimeQuestionEventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator, $state, $screen);
        };
        $onToolTerminal = static function (RuntimeEvent $event) use ($questionCoordinator, $questionController, $runtimeQuestionEventHandler): void {
            $runtimeQuestionEventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
        };

        $this->pickerController->setRuntimeRefs(
            $context->tui,
            $context->screen,
            $context->state,
            onHumanInputRequested: $onHumanInputRequested,
            onToolQuestionRequested: $onToolQuestionRequested,
            onToolTerminal: $onToolTerminal,
        );

        $liveHandler = new AgentsLiveCommandHandler($this->pickerController);
        if ($this->commandRegistry->has('agents-live')) {
            $this->commandRegistry->setHandler('agents-live', $liveHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'agents-live',
                    description: 'Open interactive live view for a subagent',
                    usage: '/agents-live',
                    acceptsArguments: false,
                ),
                $liveHandler,
            );
        }

        $mainHandler = new AgentsMainCommandHandler($context->state, $context->screen);

        if ($this->commandRegistry->has('agents-main')) {
            $this->commandRegistry->setHandler('agents-main', $mainHandler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'agents-main',
                    aliases: ['main'],
                    description: 'Return from subagent live view to the main session',
                    usage: '/agents-main',
                    acceptsArguments: false,
                ),
                $mainHandler,
            );
        }
    }
}
